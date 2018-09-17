<?php

/**
 *
 * Notice mailer CRON script
 * @author Tomáš Keske
 * @since 30.08.2018
 *
 */

function noNotification($email){
	$message = "\nDnes nebylo odesláno žádné upozornění klientovi. \n";

	$subject = "Denní seznam odeslaných upozornění (" . date("d.m.Y") . ") \n";

	$headers = 'From: noreply@proficentral.com' . "\r\n".
				"Content-type: text/html; charset=UTF-8\r\n";

	mail($email, $subject, $message, $headers);
}

//nette presenter & container initialization

$container = require __DIR__ . '/../app/bootstrap.php';

/** @var \Nette\Application\IPresenterFactory $presenterFactory */
$presenterFactory = $container->getByType(\Nette\Application\IPresenterFactory::class);

$presenter = $presenterFactory->createPresenter('Notice');
$presenter->autoCanonicalize = FALSE;

$req = new \Nette\Application\Request('Notice', 'GET', [
  'action' => 'notice'
]);

$presenter->run($req);

//include config file
$config = include("config_mail.php");

//get today
$today = date("Y-m-d");

//create database connection
$database = new mysqli($config['host'], $config['username'], $config['password'], $config['db_name']);

if ($database->connect_error){
	exit;
}

//get log of sent email dates

$email_sent_query = "SELECT * FROM email_sent WHERE date_sent = ?";

$esq_statement = $database->prepare($email_sent_query);

$esq_statement->bind_param("s", $today);

$esq_statement->execute();

$esq_result = $esq_statement->get_result();

$dates_array = $esq_result->fetch_all(MYSQLI_ASSOC);

$esq_statement->close();

//get clients from dates

foreach ($dates_array as $da){

	$client_query = "SELECT * FROM client WHERE id_client = ?";

	$client_statement = $database->prepare($client_query);

	$client_statement->bind_param("i", $da["client_id"]);

	$client_statement->execute();

	$client_result = $client_statement->get_result();

	$client_array = $client_result->fetch_array();

	$client_statement->close();

	$h[] = array('info' => $client_array["name"] . " " . $client_array["surname"] . " " . $client_array["tel"],
				 'company' => $client_array["id_company"]);

	$companys[] = $client_array["id_company"];
}

$companys = array_unique($companys);

if (!empty($companys)){

	//get companies fot that no notification has been send

	$str = implode(',', $companys);
	$query = "SELECT id_login FROM company WHERE id_company NOT IN (". $str . ")";

	$notin_statement = $database->prepare($query);

	$notin_statement->execute();

	$notin_result = $notin_statement->get_result();

	$notin_array = $notin_result->fetch_all(MYSQLI_ASSOC);

	//get their emails

	foreach($notin_array as $not){

		$query_login = "SELECT email FROM login WHERE id_login = ?";

		$login_statement = $database->prepare($query_login);

		$login_statement->bind_param("i", $not["id_login"]);

		$login_statement->execute();

		$login_result = $login_statement->get_result();

		$login_array[] = $login_result->fetch_all(MYSQLI_ASSOC);
		
	}

	//create list of companys that will receive "no notification email"
	$email_array_not_notified = [];

	if ($login_array){

		foreach($login_array as $login){
			foreach($login as $x){
				$email_array_not_notified[] = $x["email"];
			}
		}
	}


	//send email to them
	if (!empty($email_array_not_notified)){
		foreach($email_array_not_notified as $email){
			noNotification($email);
		}
	}


	//HERE STARTS BLOCK WITH ACTUAL WORTHY NOTIFICATIONS
	//get specific company from client to which email was sent

	foreach($companys as $company){

		$company_query = "SELECT id_login FROM company WHERE id_company = ?";

		$company_statement = $database->prepare($company_query);

		$company_statement->bind_param("i", $company);

		$company_statement->execute();

		$company_result = $company_statement->get_result();

		$company_array = $company_result->fetch_all();

		$company_statement->close();

		$email = null;
		$message = "Upozornění bylo odesláno těmto zákazníkům: \n\n";


		//get company email from login table with id_login we got earlier
		foreach($company_array as $ca){
			foreach($ca as $a){

				$email_query = "SELECT email FROM login WHERE id_login = ?";

				$email_statement = $database->prepare($email_query);

				$email_statement->bind_param("i", $a);

				$email_statement->execute();

				$email_result = $email_statement->get_result();

				$email_array = $email_result->fetch_all();

				$email_statement->close();

				foreach($email_array as $ea){
					foreach($ea as $e){
						$email = $e;
					}
				}
			}
		}

		$cnt = 0;

		//get customers message for specific company only

		foreach($h as $data){
			if($data["company"] == $company){
				$message .= $data["info"] . "\n";
				$cnt++;
			}
		}

		//craft the message

		$message .= "\nCelkem bylo odesláno  " .$cnt. " emailů. \n";

		$subject = "Denní seznam odeslaných upozornění (" . date("d.m.Y") . ") \n";

		$headers = 'From: noreply@proficentral.com' . "\r\n" .
				   "Content-type: text/html; charset=UTF-8\r\n";

		if($email && $message !== "Upozornění bylo odesláno těmto zákazníkům: \n\n"){
			echo "mailing";
			echo $email;

			mail($email, $subject, $message, $headers);
		}

		//END OF WORTHY BLOCK

	/* debug
		$handle = fopen("mail.txt", "a");
		fwrite($handle, $subject . "\n" . $message);
		fclose($handle);
	*/

	}
} else {

	//this branch purpose is to send "no notification" notification to administrators of companys
	//when no client of none company received notification 

	$company_query = "SELECT login.email FROM login INNER JOIN company ON login.id_login = company.id_login";

	$company_statement = $database->prepare($company_query);

	$company_statement->bind_param("i", $company);

	$company_statement->execute();

	$company_result = $company_statement->get_result();

	$company_array[] = $company_result->fetch_all();

	$company_statement->close();

	if ($company_array){
		foreach($company_array[0] as $smth){
			foreach($smth as $email){
				noNotification($email);
			}
		}
	}
}