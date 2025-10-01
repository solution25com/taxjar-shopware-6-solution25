<?php declare(strict_types=1);

namespace Shopware\Core\Content\Mail\Service;

use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\MailException;
use Shopware\Core\Content\Mail\Message\SendMailMessage;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\Subscriber\MessageQueueSizeRestrictListener;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;

#[Package('after-sales')]
class MailSender extends AbstractMailSender
{
    public const DISABLE_MAIL_DELIVERY = 'core.mailerSettings.disableDelivery';

    /**
     * Referenced from {@see MessageQueueSizeRestrictListener::MESSAGE_SIZE_LIMIT}
     * The maximum size of a message in the message queue is used to determine if a mail should be sent directly or via the message queue.
     */
    public const MAIL_MESSAGE_SIZE_LIMIT = MessageQueueSizeRestrictListener::MESSAGE_SIZE_LIMIT;

    private const BASE_FILE_SYSTEM_PATH = 'mail-data/';

    /**
     * @internal
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly FilesystemOperator $filesystem,
        private readonly SystemConfigService $configService,
        private readonly int $maxContentLength,
        private readonly LoggerInterface $logger,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
    }

    public function getDecorated(): AbstractMailSender
    {
        throw new DecorationPatternException(self::class);
    }

    public function send(Email $email): void
    {
        $disabled = $this->configService->get(self::DISABLE_MAIL_DELIVERY);

        if ($disabled) {
            $receiver = array_map(fn ($address) => $address->getAddress(), $email->getTo());

            $this->logger->info(
                'Tried to send mail but delivery is disabled.',
                [
                    'subject' => $email->getSubject(),
                    'receiver' => implode(', ', $receiver),
                    'content' => $email->getTextBody(),
                ]
            );

            return;
        }

        $deliveryAddress = $this->configService->getString('core.mailerSettings.deliveryAddress');
        if ($deliveryAddress !== '') {
            $email->addBcc($deliveryAddress);
        }

        if ($this->maxContentLength > 0 && \strlen($email->getBody()->toString()) > $this->maxContentLength) {
            throw MailException::mailBodyTooLong($this->maxContentLength);
        }

        if ($this->messageBus === null) {
            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                throw MailException::mailTransportFailedException($e);
            }

            return;
        }

        $mailData = serialize($email);

        // We add 40% buffer to the mail data length to account for the overhead of the transport envelope & serialization
        $mailDataLength = \strlen($mailData) * 1.4;
        if ($mailDataLength <= self::MAIL_MESSAGE_SIZE_LIMIT) {
            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                throw MailException::mailTransportFailedException($e);
            }

            return;
        }

        $mailDataPath = self::BASE_FILE_SYSTEM_PATH . Hasher::hash($mailData);

        $this->filesystem->write($mailDataPath, $mailData);
        $this->messageBus->dispatch(new SendMailMessage($mailDataPath));
    }
}
