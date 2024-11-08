<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\Mail;

use Hyperf\Stringable\Str;
use Hyperf\Support\Traits\ForwardsCalls;
use SwooleTW\Hyperf\Mail\Contracts\Attachable;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * @mixin \Symfony\Component\Mime\Email
 */
class Message
{
    use ForwardsCalls;

    /**
     * CIDs of files embedded in the message.
     */
    protected array $embeddedFiles = [];

    /**
     * Create a new message instance.
     */
    public function __construct(
        protected Email $message
    ) {
    }

    /**
     * Add a "from" address to the message.
     */
    public function from(array|string $address, ?string $name = null): static
    {
        is_array($address)
            ? $this->message->from(...$address)
            : $this->message->from(new Address($address, (string) $name));

        return $this;
    }

    /**
     * Set the "sender" of the message.
     */
    public function sender(array|string $address, ?string $name = null): static
    {
        is_array($address)
            ? $this->message->sender(...$address)
            : $this->message->sender(new Address($address, (string) $name));

        return $this;
    }

    /**
     * Set the "return path" of the message.
     *
     * @return $this
     */
    public function returnPath(string $address): static
    {
        $this->message->returnPath($address);

        return $this;
    }

    /**
     * Add a recipient to the message.
     */
    public function to(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->to(...$address)
                : $this->message->to(new Address($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'To');
    }

    /**
     * Remove all "to" addresses from the message.
     */
    public function forgetTo(): static
    {
        if ($header = $this->message->getHeaders()->get('To')) {
            $this->addAddressDebugHeader('X-To', $this->message->getTo());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a carbon copy to the message.
     */
    public function cc(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->cc(...$address)
                : $this->message->cc(new Address($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'Cc');
    }

    /**
     * Remove all carbon copy addresses from the message.
     */
    public function forgetCc(): static
    {
        if ($header = $this->message->getHeaders()->get('Cc')) {
            $this->addAddressDebugHeader('X-Cc', $this->message->getCC());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a blind carbon copy to the message.
     */
    public function bcc(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->bcc(...$address)
                : $this->message->bcc(new Address($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'Bcc');
    }

    /**
     * Remove all of the blind carbon copy addresses from the message.
     */
    public function forgetBcc(): static
    {
        if ($header = $this->message->getHeaders()->get('Bcc')) {
            $this->addAddressDebugHeader('X-Bcc', $this->message->getBcc());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a "reply to" address to the message.
     */
    public function replyTo(array|string $address, ?string $name = null): static
    {
        return $this->addAddresses($address, $name, 'ReplyTo');
    }

    /**
     * Add a recipient to the message.
     */
    protected function addAddresses(array|string $address, ?string $name, string $type): static
    {
        if (is_array($address)) {
            $type = lcfirst($type);

            $addresses = collect($address)->map(function ($address, $key) {
                if (is_string($key) && is_string($address)) {
                    return new Address($key, $address);
                }

                if (is_array($address)) {
                    return new Address($address['email'] ?? $address['address'], $address['name'] ?? null);
                }

                if (is_null($address)) {
                    return new Address($key);
                }

                return $address;
            })->all();

            $this->message->{"{$type}"}(...$addresses);
        } else {
            $this->message->{"add{$type}"}(new Address($address, (string) $name));
        }

        return $this;
    }

    /**
     * Add an address debug header for a list of recipients.
     *
     * @param \Symfony\Component\Mime\Address[] $addresses
     */
    protected function addAddressDebugHeader(string $header, array $addresses): static
    {
        $this->message->getHeaders()->addTextHeader(
            $header,
            implode(', ', array_map(fn ($a) => $a->toString(), $addresses)),
        );

        return $this;
    }

    /**
     * Set the subject of the message.
     */
    public function subject(string $subject): static
    {
        $this->message->subject($subject);

        return $this;
    }

    /**
     * Set the message priority level.
     */
    public function priority(int $level): static
    {
        $this->message->priority($level);

        return $this;
    }

    /**
     * Attach a file to the message.
     */
    public function attach(Attachable|Attachment|string $file, array $options = []): static
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment) {
            return $file->attachTo($this);
        }

        $this->message->attachFromPath($file, $options['as'] ?? null, $options['mime'] ?? null);

        return $this;
    }

    /**
     * Attach in-memory data as an attachment.
     */
    public function attachData(mixed $data, string $name, array $options = []): static
    {
        $this->message->attach($data, $name, $options['mime'] ?? null);

        return $this;
    }

    /**
     * Embed a file in the message and get the CID.
     */
    public function embed(Attachable|Attachment|string $file): string
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment) {
            return $file->attachWith(
                function ($path) use ($file) {
                    $cid = $file->as ?? Str::random();

                    $this->message->addPart(
                        (new DataPart(new File($path), $cid, $file->mime))->asInline()
                    );

                    return "cid:{$cid}";
                },
                function ($data) use ($file) {
                    $this->message->addPart(
                        (new DataPart($data(), $file->as, $file->mime))->asInline()
                    );

                    return "cid:{$file->as}";
                }
            );
        }

        $cid = Str::random(10);

        $this->message->addPart(
            (new DataPart(new File($file), $cid))->asInline()
        );

        return "cid:{$cid}";
    }

    /**
     * Embed in-memory data in the message and get the CID.
     */
    public function embedData(mixed $data, string $name, ?string $contentType = null): string
    {
        $this->message->addPart(
            (new DataPart($data, $name, $contentType))->asInline()
        );

        return "cid:{$name}";
    }

    /**
     * Get the underlying Symfony Email instance.
     */
    public function getSymfonyMessage(): Email
    {
        return $this->message;
    }

    /**
     * Dynamically pass missing methods to the Symfony instance.
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->forwardCallTo($this->message, $method, $parameters);
        if ($result === $this->message) {
            return $this;
        }

        return $result;
    }
}
