<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

namespace PHPCI\Plugin;

use PHPCI\Builder;
use PHPCI\Model\Build;

/**
* Email Plugin - Provides simple email capability to PHPCI.
* @author       Steve Brazier <meadsteve@gmail.com>
* @package      PHPCI
* @subpackage   Plugins
*/
class Email implements \PHPCI\Plugin
{
    /**
     * @var \PHPCI\Builder
     */
    protected $phpci;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var \Swift_Mailer
     */
    protected $mailer;

    /**
     * @var string
     */
    protected $fromAddress;

    public function __construct(Builder $phpci,
                                Build $build,
                                \Swift_Mailer $mailer,
                                array $options = array()

    )
    {
        $this->phpci        = $phpci;
        $this->build        = $build;
        $this->options      = $options;

        $phpCiSettings      = $phpci->getSystemConfig('phpci');

        $this->fromAddress = isset($phpCiSettings['email_settings']['from_address'])
                           ? $phpCiSettings['email_settings']['from_address']
                           : "notifications-ci@phptesting.org";

        $transport = \Swift_SmtpTransport::newInstance($phpCiSettings['email_settings']['smtp_address'], $phpCiSettings['email_settings']['smtp_port'])
            ->setEncryption("tls")
            ->setUsername($phpCiSettings['email_settings']['smtp_username'])
            ->setPassword($phpCiSettings['email_settings']['smtp_password']);

        $this->mailer =  \Swift_Mailer::newInstance($transport);
        //$this->mailer = $mailer;
    }

    /**
    * Connects to MySQL and runs a specified set of queries.
    */
    public function execute()
    {
        $addresses = $this->getEmailAddresses();
        // Without some email addresses in the yml file then we
        // can't do anything.
        if (count($addresses) == 0) {
            return false;
        }

        $subjectTemplate = "PHPCI - %s - %s";
        $projectName = $this->phpci->getBuildProjectTitle();
        $logText = $this->build->getLog();

        if ($this->build->isSuccessful()) {
            $sendFailures = $this->sendSeparateEmails(
                $addresses,
                sprintf($subjectTemplate, $projectName, "Passing Build"),
                sprintf("Log Output: <br><pre>%s</pre>", $logText)
            );
        } else {
            $sendFailures = $this->sendSeparateEmails(
                $addresses,
                sprintf($subjectTemplate, $projectName, "Failing Build"),
                sprintf("Log Output: <br><pre>%s</pre>", $logText)
            );
        }

        // This is a success if we've not failed to send anything.
        $this->phpci->log(sprintf("%d emails sent", (count($addresses) - count($sendFailures))));
        $this->phpci->log(sprintf("%d emails failed to send", count($sendFailures)));

        return (count($sendFailures) == 0);
    }

    /**
     * @param array|string $toAddresses   Array or single address to send to
     * @param string       $subject       Email subject
     * @param string       $body          Email body
     * @return array                      Array of failed addresses
     */
    public function sendEmail($toAddresses, $subject, $body)
    {
        $message = \Swift_Message::newInstance($subject)
            ->setFrom($this->fromAddress)
            ->setTo($toAddresses)
            ->setBody($body)
            ->setContentType("text/html");
        $failedAddresses = array();
        $this->mailer->send($message, $failedAddresses);

        return $failedAddresses;
    }

    public function sendSeparateEmails(array $toAddresses, $subject, $body)
    {
        $failures = array();
        foreach ($toAddresses as $address) {
            $newFailures = $this->sendEmail($address, $subject, $body);
            foreach ($newFailures as $failure) {
                $failures[] = $failure;
            }
        }
        return $failures;
    }

    protected function getEmailAddresses()
    {
        $addresses = array();
        $committer = $this->build->getCommitterEmail();

        if (isset($this->options['committer']) && !empty($committer)) {
            $addresses[] = $committer;
        }

        if (isset($this->options['addresses'])) {
            foreach ($this->options['addresses'] as $address) {
                $addresses[] = $address;
            }
        }

        if (isset($this->options['default_mailto_address'])) {
            $addresses[] = $this->options['default_mailto_address'];
            return $addresses;
        }
        return $addresses;
    }
}