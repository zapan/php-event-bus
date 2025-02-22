<?php

declare(strict_types=1);

namespace TeamSquad\EventBus\Infrastructure;

use DomainException;
use Exception;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use TeamSquad\EventBus\Domain\Secrets;
use Throwable;

use function array_key_exists;

class Rabbit
{
    private ?AMQPChannel $channel;
    private ?AMQPStreamConnection $connection;
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $vhost;
    private static ?self $instance = null;

    private function __construct(Secrets $secrets)
    {
        $this->host = $secrets->get('rabbit_host');
        $this->port = (int)$secrets->get('rabbit_port');
        $this->user = $secrets->get('rabbit_user');
        $this->pass = $secrets->get('rabbit_pass');
        $this->vhost = $secrets->findByKey('rabbit_vhost', '/');
        $this->channel = null;
        $this->connection = null;
    }

    public function getChannel(): ?AMQPChannel
    {
        if (!$this->channel) {
            $this->channel = $this->connect()->channel();
        }
        return $this->channel;
    }

    public static function getInstance(Secrets $secrets): self
    {
        if (!self::$instance) {
            self::$instance = new self($secrets);
        }
        return self::$instance;
    }

    /**
     * @param string $exchangeName
     * @param string $routingKey
     * @param array<array-key, mixed> $message
     * @param int|null $expiration
     * @param array<string, mixed> $applicationHeaders
     *
     * @throws JsonException
     *
     * @return void
     */
    public function publish(
        string $exchangeName,
        string $routingKey,
        array $message,
        int $expiration = null,
        array $applicationHeaders = []
    ): void {
        if ($expiration !== null && $expiration < 0) {
            throw new DomainException("RabbitWrapper publish called with invalid expiration: {$expiration}. Trying to publish message in " . $exchangeName . '(' . $routingKey . ') ');
        }

        $messageAsString = json_encode($message, JSON_THROW_ON_ERROR);
        if (!$messageAsString) {
            throw new DomainException(
                sprintf(
                    'RabbitWrapper publish called with empty message. Trying to publish message in %s(%s) backtrace: %s last old message: %s json last error: %s',
                    $exchangeName,
                    $routingKey,
                    json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20), JSON_THROW_ON_ERROR),
                    var_export($message, true),
                    json_last_error_msg()
                )
            );
        }

        $properties = [
            'content_type'     => 'application/json',
            'content_encoding' => 'utf-8',
            'app_id'           => '',
            'delivery_mode'    => 2,
        ];
        if ($expiration) {
            $properties['expiration'] = $expiration;
        }

        $toSend = new AMQPMessage($messageAsString, $properties);
        if ($applicationHeaders) {
            $toSend->set('application_headers', new AMQPTable($applicationHeaders));
        }

        $this->connect()->channel()->basic_publish($toSend, $exchangeName, $routingKey);
    }

    /**
     * @throws Exception
     */
    public function closeConnection(): void
    {
        if ($this->channel !== null) {
            $this->channel->close();
        }
        if ($this->connection !== null) {
            $this->connection->close();
        }
    }

    /**
     * @param array<string, int> $qos
     *
     * @return void
     */
    public function basicQos(array $qos): void
    {
        if ($this->channel && $this->checkQosParams($qos)) {
            $this->channel->basic_qos($qos['qosSize'], $qos['qosCount'], (bool)$qos['qosGlobal']);
        }
    }

    /**
     * @param array<string, int|bool> $qos
     *
     * @return bool
     */
    private function checkQosParams(array $qos): bool
    {
        return (array_key_exists('qosSize', $qos) &&
                array_key_exists('qosCount', $qos) &&
                array_key_exists('qosGlobal', $qos));
    }

    private function connect(int $retries = 0): AMQPStreamConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        try {
            $connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->pass,
                $this->vhost,
                false,
                'AMQPLAIN',
                null,
                'en_US',
                5.0,
                3.0,
                null,
                true
            );
            $this->connection = $connection;
            $this->channel = $connection->channel();
            $this->basicQos([
                'qosSize'   => 0,
                'qosCount'  => 1,
                'qosGlobal' => 0,
            ]);
            return $connection;
        } catch (Throwable $e) {
            if ($retries > 4) {
                throw new RuntimeException(sprintf('No se ha podido conectar al rabbit después de %d intentos: %s', $retries, $e->getMessage()));
            }

            ++$retries;
            return $this->connect($retries);
        }
    }
}
