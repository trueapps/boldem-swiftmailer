<?php
//import the transport from the standard composer directory:
require_once('./vendor/autoload.php');


$transport = new \Boldem\Transport('<CLIENT_ID>','<SECRET_CLIENT_KEY>');
$mailer = new Swift_Mailer($transport);

//Instantiate the message you want to send.
$message = (new Swift_Message('Hello from Boldem!'))
  ->setFrom(['john@example.com' => 'John Doe'])
  ->setTo(['jane@example.com'])
  ->setBody('<b>A really important message from our partners.</b>', 'text/html')
  ->addPart('Another important message from our partners.','text/plain');

//Add some attachment data:
$attachmentData = 'Some attachment data.';
$attachment = new Swift_Attachment($attachmentData, 'my-file.txt', 'application/octet-stream');

$message->attach($attachment);

//Send the message!
$mailer->send($message);
?>