<?php

namespace Boldem;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Swift_Events_EventListener;
use Swift_Mime_MimePart;
use Swift_Mime_SimpleMessage;
use Swift_Transport;

class Transport implements Swift_Transport {

	protected $version = "Unknown PHP version";
	protected $os = "Unknown OS";
    
    protected $apiUrl = 'https://api.boldem.cz/api/';

	/**
	 * The Boldem Client ID.
	 *
	 * @var string
	 */
	protected $clientId;

    /**
	 * The Boldem Secret Client Key.
	 *
	 * @var string
	 */
	protected $secretClientKey;

    /**
	 * The Boldem Secretaccess_token.
	 * For every instance access_token is obtained from Bolder API from ClientId and secretClientKey. 
	 * The access_token lifetime is 3600s, meaning this class uses it for whole lifetime. 
	 *
	 * @var string
	 */
	private $_access_token;


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
	 * GuzzleHttp client
	 */
	public $client;

	private $container;
    	
	/**
	 * Create a new Boldem transport instance.
	 *
	 * @param  string  $serverToken The API token for the server from which you will send mail.
	 * @return void
	 */
	public function __construct($clientId, $secretClientKey, array $defaultHeaders = []) {
		$this->clientId = $clientId;
        $this->secretClientKey = $secretClientKey;        
		$this->defaultHeaders = $defaultHeaders;
		$this->version = phpversion();
		$this->os = PHP_OS;
		$this->_eventDispatcher = \Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher');
		$this->configClient();
	}

	/**
     * Setup guzzle HTTP client
     */
    public function configClient() {
        $config = array();
		if ($this->_access_token) {
        	$config['headers']['Authorization'] = "Bearer ". $this->_access_token;
		}
        $config['headers']['Accept'] = "application/json;charset:utf-8";
        $config['headers']['Content-Type'] = "application/json";
        $config['headers']['X-Requested-With'] = "XMLHttpRequest";
        $config['headers']['User-Agent'] = "trueapps BoldemApi client";
        
        $this->container = [];
        $history = Middleware::history($this->container);
        $stack = HandlerStack::create();
        $stack->push($history);

        $config['verify'] = false;
        $config['exceptions'] = false;
        $config['handler'] = $stack;
        $this->client = new Client($config);                
    }

    public function getHistory()
    {
        $ret = '';
        foreach ($this->container as $transaction) {
            $ret .= print_r($transaction['request']->getHeaders(), true) . '\n';
            $ret .= (string) $transaction['request']->getBody() . '\n';
            $ret .= (string) $transaction['response']->getBody();
        }
        return $ret;
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
		if ($sendEvent = $this->_eventDispatcher->createSendEvent($this, $message)) {
			$this->_eventDispatcher->dispatchEvent($sendEvent, 'beforeSendPerformed');
			if ($sendEvent->bubbleCancelled()) {
				return 0;
			}
		}

        $payload = $this->getMessagePayload($message);
		$response = $this->client->request('POST', $this->apiUrl . 'transactionalemails', [
			'headers' => [
                'Authorization' => ['Bearer '.$this->getAccessToken()],
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
            $payload['from'] = $k;
            if ($v!='') {
                $payload['fromDisplayName'] = $v;
            }
        }

        $to = [];        
        
        $msgTo = $message->getTo();
        if (is_array($msgTo)) {
            foreach($msgTo as $k=>$v) {
                $to[] = $v!='' ? ['address' => $k, 'displayName' => $v] : ['address' => $k];
            }
        }
        $msgCc = $message->getCc();
        if (is_array($msgCc)) {
            foreach($msgCc as $k=>$v) {
                $to[] = $v!='' ? ['address' => $k, 'displayName' => $v] : ['address' => $k];
            }
        }
        $msgBcc = $message->getBcc();
        if (is_array($msgBcc)) {
            foreach($msgBcc as $k=>$v) {
                $to[] = $v!='' ? ['address' => $k, 'displayName' => $v] : ['address' => $k];
            }
        }
        
        $payload['to'] = $to;

        $msgReplyTo = $message->getReplyTo();
        if (is_array($msgReplyTo)) {         
            foreach($msgReplyTo as $k=>$v) {
                $payload['replyTo'] = $k;
				if ($v!='') {
					$payload['replyToDisplayName'] = $v;
				}
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
						$headers[$fieldName] = $value->getValue();
					}else{
						$payload["Tag"] = $value->getValue();
					}
				} else if ($value instanceof \Swift_Mime_Headers_DateHeader ||
					$value instanceof \Swift_Mime_Headers_IdentificationHeader ||
					$value instanceof \Swift_Mime_Headers_ParameterizedHeader ||
					$value instanceof \Swift_Mime_Headers_PathHeader) {
					$headers[$fieldName] = $value->getFieldBody();
					if ($value->getFieldName() == 'Message-ID') {
						$headers['X-MK-KeepID'] = true;
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
				$headers[$header] = $value;
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
	 * Get the Client ID being used by the transport.
	 *
	 * @return string
	 */
	public function getClientId() {
		return $this->clientId;
	}

	/**
	 * Set the Client ID being used by the transport.
	 *
	 * @param  string  $serverToken
	 * @return void
	 */
	public function setApiKey($clientId) {
		return $this->clientId = $clientId;
	}
    
	/**
	 * Get the API Secret Client Key being used by the transport.
	 *
	 * @return string
	 */
	public function getSecretClientKey() {
		return $this->secretClientKey;
	}

	/**
	 * Set the API key being used by the transport.
	 *
	 * @param  string  $serverToken
	 * @return void
	 */
	public function setSecretClientKey($secretClientKey) {
		return $this->secretClientKey = $secretClientKey;
	}

	/**
	 * Set access_token for current session
	 * Direct token setup is rather not expected, usually it is obtained from Boldem API
	 * @param  string  $access_token
	 * @return void
	 */
	public function setAccessToken($access_token) {
		$this->_access_token = $access_token;
	}

	/**
	 * Get access_token for API usage.
	 * If not set, obtain from Boldem API, return saved otherwise
	 * 
	 * @return string
	 */
	public function getAccessToken() {
		if (!$this->_access_token) {
			$this->obtainBearer();
		}
		return $this->_access_token;
	}

    /**
     * Obtain Bearer from Boldem API and store to object
     */
    public function obtainBearer()
    {
		$client = $this->client;
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->secretClientKey,
        ];

		$res = $client->request('POST', $this->apiUrl . 'oauth', [
			'headers' => [
			],
			'json' => $data,
			'http_errors' => false,
            'debug' => false,
		]);		

        $logins = json_decode($res->getBody(), true);
        if (isset($logins['access_token'])) {
            $expires = strtotime($logins['expires_in']);
			$this->_access_token = $logins['access_token'];
			$this->configClient();
            return true;
        }
        return false;
    }	

}
