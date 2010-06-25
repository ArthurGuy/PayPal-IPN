<?php
/**
 * This class impliments a handler for PayPal IPN notifications.
 * It will receive, confirm and decode ipn notifications.
 *
 * @author Arthur Guy <arthur@arthurguy.co.uk>
 * @copyright Copyright 2010, ArthurGuy.co.uk
 * @version 0.9
 * @example
 * Minimum Example
 *  include_once 'PayPal_IPN.php';
 *  $PayPal = new PayPal_IPN();
 *  $PayPal->set_paypal_email('your_paypal@email_address.com');
 *  $PayPal->get_ipn();
 * 
 * The returned data can then be accessed using the available variables - example
 *  $PayPal->amount;		//payment total
 *  $PayPal->customer_details['first_name']; //customer first name
 *  $PayPal->error;			//Any errors generated during data processing
 *
 */
class PayPal_IPN {
    public $paypal_email = '';		//Stores the business paypal email address
	public $debug_email = '';		//An email address for system messages to be sent to - tempoary

	public $error = '';

	public $amount = 0;				//Total value - mc_gross
	public $fee = 0;
	public $discount = 0;

	public $invoice_number = '';	//Passed through invoice number
	public $custom_number = '';		//Passed through custom number

	public $payer_id = '';
	public $receiver_id = '';

	public $payment_date = '';
	public $payment_status = '';	//Status of payment, i.e. complete, refunded
	public $payer_status = '';		//Status of customer, i.e. verified
	public $verify_sign = '';

	public $txn_id = '';			//ID associated with individual transaction - unique per txn
	public $parent_txn_id = '';		//The ID of the transaction this one was derived from
	public $txn_type = '';			//The type i.e. send_money, cart, etc...

	public $payment_type = '';		//Type i.e. instant
	public $protection_eligibility = '';	//Is there seller protection on the transaction

	public $address = array('address_1'=>'', 'address_2'=>'', 'town'=>'', 'county'=>'', 'post_code'=>'', 'country'=>'GB');
	public $address_status = '';	//The state of the address i.e. confirmed

	public $customer_details = array('first_name'=>'', 'last_name'=>'', 'email'=>'');

	public $order_rows = array();

	public $time = 0;

	/**
	 * Sets the business paypal email address - this is the account that has received the payment
	 * @param sring $email
	 */
	function set_paypal_email($email='')
	{
		$this->paypal_email = $email;
	}

	/**
	 * Sets the debug email address - some returned data will be emailed to this address
	 * @param string $email
	 */
	function set_debug_email($email='')
	{
		$this->debug_email = $email;
	}

	/**
	 * Get and confirm the ipn notification.
	 * The returned data is captured from the POST variables and then sent back to PayPal to be validated
	 * @return bool Return true or false if the process worked or failed
	 */
	function get_ipn()
	{
		if (empty($_POST))
		{
			$this->error = 'No POST Data';
			return false;
		}
		if (empty($this->paypal_email))
		{
			$this->error = 'No paypal account email address';
			return false;
		}
		// Read the post from PayPal and add 'cmd'
		$req = 'cmd=_notify-validate';
		$data_array = $this->put_post_into_array();
		
		//If the debug email is set send the details to the supplied email address
		if (!empty($this->debug_email))
		{
			$emailtext = '';
			foreach ($data_array as $key => $value)
			{
				$emailtext .= $key . " = " .$value ."\n\n";
			}
			mail($this->debug_email, "INVALID IPN", $emailtext . "\n\n" . $req);
		}

		foreach ($data_array as $key => $value)
		{
			$req .= "&$key=$value";
		}
		if ($this->send_confirm_request_to_paypal($req))
		{
			if ($data_array['receiver_email'] != $this->paypal_email)
			{
				//Incorrect receipt email - error
				$this->error = 'Incorrect receiver email address';
			}

			//Store returned data
			$this->amount = $data_array['mc_gross'];
			$this->fee = $data_array['mc_fee'];
			$this->discount = $data_array['discount'];
			$this->invoice_number = $data_array['invoice'];
			$this->custom_number = $data_array['custom'];

			$this->payer_id = $data_array['payer_id'];
			$this->receiver_id = $data_array['receiver_id'];

			$this->payment_date = $data_array['payment_date'];
			$this->payment_status = strtolower($data_array['payment_status']);
			$this->payer_status = strtolower($data_array['payer_status']);
			$this->verify_sign = $data_array['verify_sign'];

			$this->txn_id = $data_array['txn_id'];
			$this->parent_txn_id = $data_array['parent_txn_id'];
			$this->txn_type = $data_array['txn_type'];

			$this->payment_type = strtolower($data_array['payment_type']);
			$this->protection_eligibility = strtolower($data_array['protection_eligibility']);

			$this->address_status = $data_array['address_status'];

			$this->customer_details = array('first_name'=>urldecode($data_array['first_name']),
											'last_name'=>urldecode($data_array['last_name']),
											'email'=>urldecode($data_array['payer_email']));

			$this->address = array( 'address_name'=>urldecode($data_array['address_name']),
									'address_street'=>urldecode($data_array['address_street']),
									'town'=>urldecode($data_array['address_city']),
									'post_code'=>urldecode($data_array['address_zip']),
									'country'=>urldecode($data_array['address_country']),
									'country_code'=>urldecode($data_array['address_country_code']));

			$item = 0;
			$data_array['num_cart_items'];
			while ($item < $data_array['num_cart_items'])
			{
				$num = $item+1;
				$this->order_rows[$item] = array(   'item_number'=>urldecode($data_array['item_number'.$num]),
													'item_name'=>urldecode($data_array['item_name'.$num]),
													'amount'=>urldecode($data_array['mc_gross_'.$num]),
													'quantity'=>urldecode($data_array['quantity'.$num]),
													//'weight'=>urldecode($data_array['weight'.$num]),
													'shipping'=>urldecode($data_array['mc_shipping'.$num]),
													'handling'=>urldecode($data_array['mc_handling'.$num]),
													//'discount_amount'=>urldecode($data_array['discount_amount'.$num]),
													//'discount_rate'=>urldecode($data_array['discount_rate'.$num]),
													'tax'=>urldecode($data_array['tax'.$num])
												);
				$item++;
			}

			$this->time = strtotime(urldecode($data_array['payment_date']));
			return true;
		}
		else
		{
			$this->error = 'Error verfying data with PayPal';
			return false;
		}
	}

	/**
	 * Gets the post data and puts it into a key, value array.
	 * Any escaping of data inserted by magic quotes is removed.
	 * @return array The POST data
	 */
	private function put_post_into_array()
	{
		$data_array = array();
		if(function_exists('get_magic_quotes_gpc'))
		{
			$get_magic_quotes_exits = true;
		}
		// Handle escape characters, which depends on setting of magic quotes
		foreach ($_POST as $key => $value)
		{
			if(($get_magic_quotes_exists == true) && (get_magic_quotes_gpc() == 1))
			{
				$value = urlencode(stripslashes($value));
			}
			else
			{
				$value = urlencode($value);
			}
			$data_array[$key] = $value;
		}
		return $data_array;
	}

	/**
	 * Sends the passed data back to PayPal and returns true if its valid
	 * @param string $data The string of data to send back to PayPal
	 * @return bool Returns True if the data is valid or False if it isnt or there was an error
	 */
	private function send_confirm_request_to_paypal($data='')
	{
		if (empty($data))
			return false;
		$errno = '';
		$errstr = '';
		$header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($data) . "\r\n\r\n";
		$fp = fsockopen('www.paypal.com', 80, $errno, $errstr, 30);
		if (!$fp)
		{
			//Error opening socket to paypal;
			$this->error = "$errstr ($errno)";
			return false;
		}
		else
		{
			//Send the data back to PayPal
			fwrite($fp, $header . $data);
			while (!feof($fp))
			{
				//Get the result and see if its verified or invalid
				$result = fgets($fp, 1024);
				if (strcmp($result, "VERIFIED") == 0)
				{
					return true;
				}
				else if (strcmp($result, "INVALID") == 0)
				{
					$this->error = 'INVALID';
					return false;
				}
			}
		}
		return false;
	}
}
?>