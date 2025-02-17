<?php

declare(strict_types=1);

namespace LaravelHyperf\Mail\Transport;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

class SesTransport extends AbstractTransport
{
    /**
     * Create a new SES transport instance.
     */
    public function __construct(
        protected SesClient $ses,
        protected array $options = []
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $options = $this->options;

        if ($message->getOriginalMessage() instanceof Message) {
            foreach ($message->getOriginalMessage()->getHeaders()->all() as $header) {
                if ($header instanceof MetadataHeader) {
                    $options['Tags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
                }
            }
        }

        try {
            $result = $this->ses->sendRawEmail(
                array_merge(
                    $options,
                    [
                        'Source' => $message->getEnvelope()->getSender()->toString(),
                        'Destinations' => collect($message->getEnvelope()->getRecipients())
                            ->map
                            ->toString()
                            ->values()
                            ->all(),
                        'RawMessage' => [
                            'Data' => $message->toString(),
                        ],
                    ]
                )
            );
        } catch (AwsException $e) {
            $reason = $e->getAwsErrorMessage() ?? $e->getMessage();

            throw new TransportException(
                sprintf('Request to AWS SES API failed. Reason: %s.', $reason),
                is_int($e->getCode()) ? $e->getCode() : 0,
                $e
            );
        }

        $messageId = $result->get('MessageId');

        /* @phpstan-ignore-next-line */
        $message->getOriginalMessage()->getHeaders()->addHeader('X-Message-ID', $messageId);
        /* @phpstan-ignore-next-line */
        $message->getOriginalMessage()->getHeaders()->addHeader('X-SES-Message-ID', $messageId);
    }

    /**
     * Get the Amazon SES client for the SesTransport instance.
     */
    public function ses(): SesClient
    {
        return $this->ses;
    }

    /**
     * Get the transmission options being used by the transport.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the transmission options being used by the transport.
     */
    public function setOptions(array $options): array
    {
        return $this->options = $options;
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'ses';
    }
}
