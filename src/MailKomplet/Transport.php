<?php

namespace Boldem;

use GuzzleHttp\Client;
use Swift_Events_EventListener;
use Swift_Mime_MimePart;
use Swift_Mime_SimpleMessage;
use Swift_Transport;

class Transport implements Swift_Transport {

	protected $version = "Unknown PHP version";
	protected $os = "Unknown OS";
    
    protected $apiUrl = 'https://api.boldem.cz/v1/';

	/**
	 * The Boldem API key.
	 *
	 * @var string
	 */
	protected $apiKey;

    /**
	 * The Boldem baseCrypt.
	 *
	 * @var string
	 */
	protected $baseCrypt;

	/**
	 * A set of default headers to attach to every message
	 *
	 * @var array
	 */
	protected $defaultHeaders = [];

	/**
	 * @var \Swift_Events_EventDispatcher
	 */
	protected $_eventDispatcher;

	/**
	 * Create a new Boldem transport instance.
	 *
	 * @param  string  $serverToken The API token for the server from which you will send mail.
	 * @return void
	 */
	public function __construct($baseCrypt, $apiKey, array $defaultHeaders = []) {
		$this->baseCrypt = $baseCrypt;
        $this->apiKey = $apiKey;        
		$this->defaultHeaders = $defaultHeaders;
		$this->version = phpversion();
		$this->os = PHP_OS;
		$this->_eventDispatcher = \Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher');
	}

	/**
	 * {@inheritdoc}
	 */
	public function isStarted() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function start() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stop() {
		return true;
	}

	/**
	 * Not used
	 *
	 * @return bool
	 */
	public function ping() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null) {
		$client = $this->getHttpClient();

		if ($sendEvent = $this->_eventDispatcher->createSendEvent($this, $message)) {
			$this->_eventDispatcher->dispatchEvent($sendEvent, 'beforeSendPerformed');
			if ($sendEvent->bubbleCancelled()) {
				return 0;
			}
		}

		$v = $this->version;
		$o = $this->os;

        $payload = ['email' => $this->getMessagePayload($message)];
		$response = $client->request('POST', $this->apiUrl . $this->baseCrypt . '/transactionalEmails', [
			'headers' => [
                'Authorization' => ['Basic '.$this->apiKey],
                'Accept' => 'application/json;charset:utf-8',
				'Content-Type' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
				'User-Agent' => "mailkomplet-swiftmailer (PHP Version: $v, OS: $o)",
			],
			'json' => $payload,
			'http_errors' => false,
            'debug' => false,
		]);

        
		$success = $response->getStatusCode() === 200;

		if ($responseEvent = $this->_eventDispatcher->createResponseEvent($this, $response->getBody()->__toString(), $success)) {
			$this->_eventDispatcher->dispatchEvent($responseEvent, 'responseReceived');
		}

		if ($sendEvent) {
			$sendEvent->setResult($success ? \Swift_Events_SendEvent::RESULT_SUCCESS : \Swift_Events_SendEvent::RESULT_FAILED);
			$this->_eventDispatcher->dispatchEvent($sendEvent, 'sendPerformed');
		}
		
		return $success
			? $this->getRecipientCount($message)
			: 0;
	}

	/**
	 * Get the number of recipients for a message
	 *
	 * @param Swift_Mime_SimpleMessage $message
	 * @return int
	 */
	protected function getRecipientCount(Swift_Mime_SimpleMessage $message) {
	    return count(array_merge(
            (array) $message->getTo(),
            (array) $message->getCc(),
            (array) $message->getBcc())
        );
	}

	/**
	 * Gets MIME parts that match the message type.
	 * Excludes parts of type \Swift_Mime_Attachment as those
	 * are handled later.
	 *
	 * @param  Swift_Mime_SimpleMessage  $message
	 * @param  string                    $mimeType
	 * @return Swift_Mime_MimePart
	 */
	protected function getMIMEPart(Swift_Mime_SimpleMessage $message, $mimeType) {
		foreach ($message->getChildren() as $part) {
			if (strpos($part->getContentType(), $mimeType) === 0 && !($part instanceof \Swift_Mime_Attachment)) {
				return $part;
			}
		}
	}

	/**
	 * Convert a Swift Mime Message to a Boldem Payload.
	 *
	 * @param  Swift_Mime_SimpleMessage  $message
	 * @return object
	 */
	protected function getMessagePayload(Swift_Mime_SimpleMessage $message) {
		$payload = [];

		$this->processRecipients($payload, $message);

		$this->processMessageParts($payload, $message);

		if ($message->getHeaders()) {
			$this->processHeaders($payload, $message);
		}

		return $payload;
	}

	/**
	 * Applies the recipients of the message into the API Payload.
	 *
	 * @param  array                     $payload
	 * @param  Swift_Mime_SimpleMessage  $message
	 * @return object
	 */
	protected function processRecipients(&$payload, $message) {
  		$payload['subject'] = $message->getSubject();

        $msgFrom = $message->getFrom();
        foreach($msgFrom as $k=>$v) {
            $payload['fromAddress'] = $k;
            if ($v!='') {
                $payload['fromDisplayName'] = $v;
            }
        }

        $to = [];        
        
        $msgTo = $message->getTo();
        if (is_array($msgTo)) {
            foreach($msgTo as $k=>$v) {
                $to[] = ['address' => $k];
            }
        }
        $msgCc = $message->getCc();
        if (is_array($msgCc)) {
            foreach($msgCc as $k=>$v) {
                $to[] = ['address' => $k];
            }
        }
        $msgBcc = $message->getBcc();
        if (is_array($msgBcc)) {
            foreach($msgBcc as $k=>$v) {
                $to[] = ['address' => $k];
            }
        }
        
        $payload['to'] = $to;

        $msgReplyTo = $message->getReplyTo();
        if (is_array($msgReplyTo)) {         
            foreach($msgReplyTo as $k=>$v) {
                $payload['replyTo'] = $k;
            }
        }
	}

	/**
	 * Applies the message parts and attachments
	 * into the API Payload.
	 *
	 * @param  array                     $payload
	 * @param  Swift_Mime_SimpleMessage  $message
	 * @return object
	 */
	protected function processMessageParts(&$payload, $message) {
		//Get the primary message.
		switch ($message->getContentType()) {
			case 'text/html':
			case 'multipart/alternative':
			case 'multipart/mixed':
				$payload['bodyHtml'] = $message->getBody();
				break;
			default:
				$payload['bodyText'] = $message->getBody();
				break;
		}

		// Provide an alternate view from the secondary parts.
		if ($plain = $this->getMIMEPart($message, 'text/plain')) {
			$payload['bodyText'] = $plain->getBody();
		}
		if ($html = $this->getMIMEPart($message, 'text/html')) {
			$payload['bodyHtml'] = $html->getBody();
		}
		if ($message->getChildren()) {
			$payload['attachments'] = array();
			foreach ($message->getChildren() as $attachment) {
				if (is_object($attachment) and $attachment instanceof \Swift_Mime_Attachment) {
					$a = array(
						'name' => $attachment->getFilename(),
						'content' => base64_encode($attachment->getBody()),
						'contentType' => $attachment->getContentType()
					);
					if($attachment->getDisposition() != 'attachment' && $attachment->getId() != NULL) {
						$a['ContentID'] = 'cid:'.$attachment->getId();
					}
					$payload['attachments'][] = $a;
				}
			}
		}
	}

	/**
	 * Applies the headers into the API Payload.
	 *
	 * @param  array                     $payload
	 * @param  Swift_Mime_SimpleMessage  $message
	 * @return object
	 */
	protected function processHeaders(&$payload, $message) {
		$headers = [];
		$headersSetInMessage = [];

		foreach ($message->getHeaders()->getAll() as $key => $value) {
			$fieldName = $value->getFieldName();

			$excludedHeaders = ['Subject', 'Content-Type', 'MIME-Version', 'Date'];

			if (!in_array($fieldName, $excludedHeaders)) {
				$headersSetInMessage[$fieldName] = true;

				if ($value instanceof \Swift_Mime_Headers_UnstructuredHeader ||
					$value instanceof \Swift_Mime_Headers_OpenDKIMHeader) {
					if($fieldName != 'X-MK-Tag'){
						array_push($headers, [
							"Name" => $fieldName,
							"Value" => $value->getValue(),
						]);
					}else{
						$payload["Tag"] = $value->getValue();
					}
				} else if ($value instanceof \Swift_Mime_Headers_DateHeader ||
					$value instanceof \Swift_Mime_Headers_IdentificationHeader ||
					$value instanceof \Swift_Mime_Headers_ParameterizedHeader ||
					$value instanceof \Swift_Mime_Headers_PathHeader) {
					array_push($headers, [
						"Name" => $fieldName,
						"Value" => $value->getFieldBody(),
					]);

					if ($value->getFieldName() == 'Message-ID') {
						array_push($headers, [
							"Name" => 'X-MK-KeepID',
							"Value" => 'true',
						]);
					}
				}
			}
		}

		// we process the default headers after, because in an e-mail every
		// header can be present multiple times $headers is a list and not
		// a key-value map. The default headers are only added if there is no
		// header present with the same name one **or** multiple times.
		//
		// Default headers do not support being appended to existing headers
		// with the same name.
		foreach ($this->defaultHeaders as $header => $value) {
			if (isset($headersSetInMessage[$header])) {
				continue;
			}

			if ($header === 'X-MK-Tag') {
				$payload["Tag"] = $value;
			} else {
				array_push($headers, [
					"Name" => $header,
					"Value" => $value,
				]);
			}
		}

		$payload['headers'] = $headers;
	}

	/**
	 * {@inheritdoc}
	 */
	public function registerPlugin(Swift_Events_EventListener $plugin) {
		$this->_eventDispatcher->bindEventListener($plugin);
	}

	/**
	 * Get a new HTTP client instance.
	 *
	 * @return \GuzzleHttp\Client
	 */
	protected function getHttpClient() {
		return new Client;
	}

	/**
	 * Get the API key being used by the transport.
	 *
	 * @return string
	 */
	public function getApiKey() {
		return $this->apiKey;
	}

	/**
	 * Set the API key being used by the transport.
	 *
	 * @param  string  $serverToken
	 * @return void
	 */
	public function setApiKey($apiKey) {
		return $this->apiKey = $apiKey;
	}
    
	/**
	 * Get the API baseCrypt being used by the transport.
	 *
	 * @return string
	 */
	public function getBaseCrypt() {
		return $this->baseCrypt;
	}

	/**
	 * Set the API key being used by the transport.
	 *
	 * @param  string  $serverToken
	 * @return void
	 */
	public function setBaseCrypt($baseCrypt) {
		return $this->baseCrypt = $baseCrypt;
	}
    

}
