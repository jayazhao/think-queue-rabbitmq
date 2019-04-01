<?php
/**
 * @author jayazhao
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */

namespace jayazhao\queue\connector;

use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpContext;
use Enqueue\AmqpTools\DelayStrategyAware;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use think\facade\Log;
use think\helper\Str;
use think\queue\Connector;
use jayazhao\queue\job\RabbitMQ as RabbitMQJob;

class RabbitMQ extends Connector
{

    /* @var AmqpContext */
    protected $context;

    protected $options = [
        'dsn'        => '',
        'host'       => '127.0.0.1',
        'port'       => 5672,
        'login'      => 'guest',
        'password'   => 'guest',
        'vhost'      => '/',
        'ssl_params' => [
            'ssl_on' => false,
            'cafile' => null,
            'local_cert' => null,
            'local_key' => null,
            'verify_peer' => true,
            'passphrase' => null,
        ],
    ];

    protected $queueName;

    protected $queueOptions;
    protected $exchangeOptions;

    protected $declaredExchanges = [];
    protected $declaredQueues = [];

    protected $sleepOnError;

    public function __construct(array $options)
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $factory = new AmqpConnectionFactory([
            'dsn' => $this->options['dsn'],
            'host' => $this->options['host'],
            'port' => $this->options['port'],
            'user' => $this->options['login'],
            'pass' => $this->options['password'],
            'vhost' => $this->options['vhost'],
            'ssl_on' => $this->options['ssl_params']['ssl_on'],
            'ssl_verify' => $this->options['ssl_params']['verify_peer'],
            'ssl_cacert' => $this->options['ssl_params']['cafile'],
            'ssl_cert' => $this->options['ssl_params']['local_cert'],
            'ssl_key' => $this->options['ssl_params']['local_key'],
            'ssl_passphrase' => $this->options['ssl_params']['passphrase'],
        ]);

        if ($factory instanceof DelayStrategyAware) {
            $factory->setDelayStrategy(new RabbitMqDlxDelayStrategy());
        }

        $this->context = $factory->createContext();
        $this->queueName = $this->options['queue'] ?? $this->options['options']['queue']['name'];
        $this->queueOptions = $this->options['options']['queue'];
        $this->queueOptions['arguments'] = isset($this->queueOptions['arguments']) ?
            json_decode($this->queueOptions['arguments'], true) : [];

        $this->exchangeOptions = $this->options['options']['exchange'];
        $this->exchangeOptions['arguments'] = isset($this->exchangeOptions['arguments']) ?
            json_decode($this->exchangeOptions['arguments'], true) : [];

        $this->sleepOnError = $this->options['sleep_on_error'] ?? 5;
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data, $queue), $queue, []);
    }

    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        try {
            /**
             * @var AmqpTopic
             * @var AmqpQueue $queue
             */
            [$queue, $topic] = $this->declareEverything($queueName);

            $message = $this->context->createMessage($payload);
            $message->setRoutingKey($queue->getQueueName());
            $message->setCorrelationId(uniqid('', true));
            $message->setContentType('application/json');
            $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

            if (isset($this->options['headers'])) {
                $message->setHeaders($this->options['headers']);
            }

            if (isset($this->options['properties'])) {
                $message->setProperties($this->options['properties']);
            }

            if (isset($this->options['attempts'])) {
                $message->setProperty(RabbitMQJob::ATTEMPT_COUNT_HEADERS_KEY, $this->options['attempts']);
            }

            $producer = $this->context->createProducer();
            if (isset($options['delay']) && $options['delay'] > 0) {
                $producer->setDeliveryDelay($options['delay'] * 1000);
            }

            $producer->send($topic, $message);

            return $message->getCorrelationId();
        } catch (\Exception $exception) {
            $this->reportConnectionError('pushRaw', $exception);

            return;
        }
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job,$data, $queue), $queue, ['delay' => $delay]);
    }

    public function pop($queueName = null)
    {
        try {
            /** @var AmqpQueue $queue */
            [$queue] = $this->declareEverything($queueName);

            $consumer = $this->context->createConsumer($queue);

            if ($message = $consumer->receiveNoWait()) {
                return new RabbitMQJob($this, $consumer, $message);
            }
        } catch (\Throwable $exception) {
            $this->reportConnectionError('pop', $exception);

            return;
        }
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param  \DateTimeInterface|\DateInterval|int $delay
     * @param  string|object $job
     * @param  mixed $data
     * @param  string $queue
     * @param  int $attempts
     * @return mixed
     */
    public function release($delay, $job, $data, $queue, $attempts = 0)
    {
        return $this->pushRaw($this->createPayload($job, $data, $queue), $queue, [
            'delay' => $delay,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Create a payload string from the given job and data.
     *
     * @param  string  $job
     * @param  string  $queue
     * @param  mixed   $data
     * @return string
     */
    protected function createPayload($job, $data = '', $queue = null)
    {
        $payload = $this->setMeta(
            parent::createPayload($job, $data), 'id', $this->getRandomId()
        );

        return $this->setMeta($payload, 'attempts', 1);
    }

    /**
     * 随机id
     *
     * @return string
     */
    protected function getRandomId()
    {
        return Str::random(32);
    }


    /**
     * @param string $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    protected function declareEverything(string $queueName = null)
    {
        $queueName = $this->getQueueName($queueName);
        $exchangeName = $this->exchangeOptions['name'] ?: $queueName;

        $topic = $this->context->createTopic($exchangeName);
        $topic->setType($this->exchangeOptions['type']);
        $topic->setArguments($this->exchangeOptions['arguments']);
        if ($this->exchangeOptions['passive']) {
            $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        }
        if ($this->exchangeOptions['durable']) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }
        if ($this->exchangeOptions['auto_delete']) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->exchangeOptions['declare'] && ! in_array($exchangeName, $this->declaredExchanges, true)) {
            $this->context->declareTopic($topic);

            $this->declaredExchanges[] = $exchangeName;
        }

        $queue = $this->context->createQueue($queueName);
        $queue->setArguments($this->queueOptions['arguments']);
        if ($this->queueOptions['passive']) {
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        }
        if ($this->queueOptions['durable']) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }
        if ($this->queueOptions['exclusive']) {
            $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        }
        if ($this->queueOptions['auto_delete']) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        if ($this->queueOptions['declare'] && ! in_array($queueName, $this->declaredQueues, true)) {
            $this->context->declareQueue($queue);

            $this->declaredQueues[] = $queueName;
        }

        if ($this->queueOptions['bind']) {
            $this->context->bind(new AmqpBind($queue, $topic, $queue->getQueueName()));
        }

        return [$queue, $topic];
    }

    protected function getQueueName($queueName = null)
    {
        return $queueName ?: $this->queueName;
    }

    /**
     * @param string $action
     * @param \Throwable $e
     * @throws \Exception
     */
    protected function reportConnectionError($action, \Throwable $e)
    {

        Log::error('AMQP error while attempting '.$action.': '.$e->getMessage());

        // If it's set to false, throw an error rather than waiting
        if ($this->sleepOnError === false) {
            throw new \RuntimeException('Error writing data to the connection with RabbitMQ', null, $e);
        }

        // Sleep so that we don't flood the log file
        sleep($this->sleepOnError);
    }
}
