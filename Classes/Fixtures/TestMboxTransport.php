<?php
/**
 ************************************************************************
 * Copyright (c) 2005-2020 Stefan (Mütze) Horst                        *
 ************************************************************************
 * I don't have the time to read through all the licences to find out   *
 * what they exactly say. But it's simple. It's free for non-commercial *
 * projects, but as soon as you make money with it, i want my share :-) *
 * (License : Free for non-commercial use)                              *
 ************************************************************************
 * Authors: Stefan (Mütze) Horst <muetze@scoutnet.de>               *
 ************************************************************************
 */

namespace ScoutNet\TestingTools\Fixtures;

use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use ZBateson\MailMimeParser\Message;

class TestMboxTransport extends AbstractTransport
{
    public const SEPERATOR = "\n\nSEPERATOR ******1587929168***** SEPERATOR\n\n";
    /**
     * @var string The file to write our mails into
     */
    private $mboxFile;

    /**
     * Create a new MailTransport
     *
     * @param array $mailSettings
     * @throws Exception
     */
    public function __construct($mailSettings)
    {
        parent::__construct();

        $mboxFile = $mailSettings['transport_mbox_file']??'';
        if ($mboxFile === '') {
            throw new Exception('$GLOBALS[\'TYPO3_CONF_VARS\'][\'MAIL\'][\'transport_mbox_file\'] needs to be set when transport is set to "this transport".', 1294586645);
        }

        $this->mboxFile = $mboxFile;
        $this->setMaxPerSecond(0);
    }

    /**
     * Outputs the mail to a text file according to RFC 4155.
     *
     * @param SentMessage $message
     *
     * @throws LockCreateException
     * @throws LockAcquireException
     * @throws LockAcquireWouldBlockException
     */
    protected function doSend(SentMessage $message): void
    {
        // Add the complete mail inclusive headers
        /** @var LockFactory $lockFactory */
        $lockFactory = GeneralUtility::makeInstance(LockFactory::class);

        $lockObject = $lockFactory->createLocker('TestMboxTransport');
        $lockObject->acquire();

        $firstMessage = !is_file($this->mboxFile);
        // Write the mbox file
        $file = @fopen($this->mboxFile, 'ab');
        if (!$file) {
            $lockObject->release();
            throw new RuntimeException(sprintf('Could not write to file "%s" when sending an email to debug transport', $this->mboxFile), 1291064151);
        }
        if (!$firstMessage) {
            @fwrite($file, self::SEPERATOR);
        }

        @fwrite($file, 'X-Envelope-To: ' . implode(', ', array_map(static function ($x) { return $x->toString(); }, $message->getEnvelope()->getRecipients())) . "\r\n");
        @fwrite($file, $message->toString());
        @fclose($file);
        GeneralUtility::fixPermissions($this->mboxFile);
        $lockObject->release();
    }

    public function __toString(): string
    {
        return $this->mboxFile;
    }

    /**
     * @param $mboxPath
     *
     * @return Message[]
     */
    public static function parseMbox($mboxPath): array
    {
        $mbox = file_get_contents($mboxPath);

        $mboxArray = [];
        foreach (explode(self::SEPERATOR, $mbox) as $message) {
            $mboxArray[] = Message::from($message);
        }

        return $mboxArray;
    }
}
