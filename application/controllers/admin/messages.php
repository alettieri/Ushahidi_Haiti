<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Messages Controller.
 * View SMS Messages Received Via FrontlineSMS
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Admin Messages Controller
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL)
 */

define("LOCK_PERIOD", 1800); // 30 MINUTES

class Messages_Controller extends Admin_Controller
{	
	function __construct()
	{
		parent::__construct();

		$this->template->this_page = 'messages';
		
		// REMOVE ALL LOCKS
		$this->_remove_locks();
	}

	/**
	* Lists the messages.
    * @param int $service_id
    */
	function index($service_id = 1)
	{
		$this->template->content = new View('admin/messages');
		
		$filter = "";
		
		// Get Title
		$service = ORM::factory('service', $service_id);
		$this->template->content->title = $service->service_name;

        //So far this assumes that selected 'message_id's are for deleting
		if (isset($_POST['message_id']))
		{
			if ($_POST['action'] == 'rank') 
			{
				$this->_rankMessages($_POST['message_id'], $_POST['level']);
			} else 
			{	
				$this->_deleteMessages($_POST['message_id']);
			}
		}

		// Is this an Inbox or Outbox Filter?
		if (!empty($_GET['type']))
		{
			$type = $_GET['type'];

			if ($type == '2')
			{
				$filter .= ' message.incident_id = 0 ';
			}
			elseif ($type == '3')
			{
				$filter .= ' message.incident_id > 0 ';
			}
			else
			{
				$type = "1";
				$filter .= ' 1=1 ';
			}
		}
		else
		{
			$type = "1";
			$filter .= ' 1=1 ';
		}
		
		// Any time period filter?
		$period = 'a';
		if (!empty($_GET['period']))
		{
			$period = $_GET['period'];
			
			if ($period == 'd')
			{
				$message_date = date("Y-m-d 00:00:00", mktime(0, 0, 0,date("m"),date("d")-1,date("Y")));
				$end_date = date("Y-m-d 00:00:00", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
			} elseif ($period == 'm')
			{
				$message_date = date("Y-m-01 00:00:00", mktime(0, 0, 0, date("m")-1, date("d"), date("Y")));
				$end_date = date("Y-m-01 00:00:00", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
			} elseif ($period == 'y')
			{
				$message_date = date("Y-01-01 00:00:00", mktime(0, 0, 0, date("m"), date("d"), date("Y")-1));
				$end_date = date("Y-01-01 00:00:00", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
			}
			
			if (isset($message_date))
			{
				$filter .= " AND message_date >= '" . $message_date . "' AND message_date < '" . $end_date ."'";
			}
		}

		// check, has the form been submitted?
		$form_error = FALSE;
		$form_saved = FALSE;
		$form_action = "";
		
		// Pagination
		$pagination = new Pagination(array(
			'query_string'   => 'page',
			'items_per_page' => (int) Kohana::config('settings.items_per_page_admin'),
			'total_items'    => ORM::factory('message')
				->join('reporter','message.reporter_id','reporter.id')
				->where($filter)
				->where('parent_id', 0)
				->where('message_trash',0)
				->where('service_id', $service_id)
				->count_all()
		));

		$messages = ORM::factory('message')
			->join('reporter','message.reporter_id','reporter.id')
			->where('service_id', $service_id)
			->where($filter)
			->where('parent_id', 0)
			->where('message_trash',0)
			->orderby('message_date_reply','desc')
			->orderby('message_date','desc')
			->find_all((int) Kohana::config('settings.items_per_page_admin'), $pagination->sql_offset);
			
		$replies = ORM::factory('message');

		$this->template->content->messages = $messages;
		$this->template->content->replies = $replies;
		$this->template->content->service_id = $service_id;
		$this->template->content->services = ORM::factory('service')->find_all();
		$this->template->content->pagination = $pagination;
		$this->template->content->form_error = $form_error;
		$this->template->content->form_saved = $form_saved;
		$this->template->content->form_action = $form_action;
		
		$levels = ORM::factory('level')->orderby('level_weight')->find_all();
		$this->template->content->levels = $levels;

		// Total Reports
		$this->template->content->total_items = $pagination->total_items;

		// Message Type Tab - Inbox/Outbox
		$this->template->content->type = $type;
		$this->template->content->period = $period;
		
		// Javascript Header
		$this->template->js = new View('admin/messages_js');
	}

	/**
	* Send A New Message Using Clickatell Library
    */
	function send()
	{
		$this->template = "";
		$this->auto_render = FALSE;

		// setup and initialize form field names
		$form = array
	    (
			'to_id' => '',
			'message' => ''
	    );
        //  Copy the form as errors, so the errors will be stored with keys
        //  corresponding to the form field names
        $errors = $form;
		$form_error = FALSE;

		// check, has the form been submitted, if so, setup validation
	    if ($_POST)
	    {
            // Instantiate Validation, use $post, so we don't overwrite $_POST
            // fields with our own things
            $post = new Validation($_POST);

	        // Add some filters
	        $post->pre_filter('trim', TRUE);

	        // Add some rules, the input field, followed by a list of checks, carried out in order
			$post->add_rules('to_id', 'required', 'numeric');
			$post->add_rules('message', 'required', 'length[1,160]');

			// Test to see if things passed the rule checks
	        if ($post->validate())
	        {
				// Yes! everything is valid
				$reply_to = ORM::factory('message', $post->to_id);
				if ($reply_to->loaded == true) {
					// Yes! Replyto Exists
					// This is the message we're replying to
					$sms_to = intval($reply_to->message_from);

					// Load Users Settings
					$settings = new Settings_Model(1);
					if ($settings->loaded == true) {
						// Get SMS Numbers
						if (!empty($settings->sms_no3)) {
							$sms_from = $settings->sms_no3;
						}elseif (!empty($settings->sms_no2)) {
							$sms_from = $settings->sms_no2;
						}elseif (!empty($settings->sms_no1)) {
							$sms_from = $settings->sms_no1;
						}else{
							$sms_from = "000";		// User needs to set up an SMS number
						}

						// Create Clickatell Object
						$mysms = new Clickatell();
						$mysms->api_id = $settings->clickatell_api;
						$mysms->user = $settings->clickatell_username;
						$mysms->password = $settings->clickatell_password;
						$mysms->use_ssl = false;
						$mysms->sms();
						$send_me = $mysms->send ($sms_to, $sms_from, $post->message);

						// Message Went Through??
						if ($send_me == "OK") {
							$newmessage = ORM::factory('message');
							$newmessage->parent_id = $post->to_id;	// The parent message
							$newmessage->message_from = $sms_from;
							$newmessage->message_to = $sms_to;
							$newmessage->message = $post->message;
							$newmessage->message_type = 2;			// This is an outgoing message
							$newmessage->reporter_id = $reply_to->reporter_id;
							$newmessage->message_date = date("Y-m-d H:i:s",time());
							$newmessage->save();

							echo json_encode(array("status"=>"sent", "message"=>"Your message has been sent!"));
						}
						// Message Failed
						else {
							echo json_encode(array("status"=>"error", "message"=>"Error! - " . $send_me));
						}
					}
					else
					{
						echo json_encode(array("status"=>"error", "message"=>"Error! Please check your SMS settings!"));
					}
				}
				// Send_To Mobile Number Doesn't Exist
				else {
					echo json_encode(array("status"=>"error", "message"=>"Error! Please make sure your message is valid!"));
				}
	        }

            // No! We have validation errors, we need to show the form again,
            // with the errors
            else
	        {
	            // populate the error fields, if any
	            $errors = arr::overwrite($errors, $post->errors('messages'));
				echo json_encode(array("status"=>"error", "message"=>"Error! Please make sure your message is valid!"));
	        }
	    }

	}


	/**
	* Mark a message as read
    */
	function read($message_id = NULL)
	{
		$this->template = "";
		$this->auto_render = FALSE;
		
		if ($message_id)
		{
			$message = ORM::factory('message')->find($message_id);
			if ($message->loaded)
			{
				$message->message_read = 0;
				$message->save($message_id);
			}
		}
	}
	
	/**
	* Lock a message while editing
    */
	function lock($message_id = 0)
	{		
		$this->template = "";
		$this->auto_render = FALSE;
		
		// Is the Admin Logged In? Just Double Check
		$user_id = Auth::instance()->logged_in()
		     ? $_SESSION['auth_user']->id
		     : 0;
		
		// Current Time Timestamp
		$timestamp = time();
		
		if ($message_id && $user_id)
		{
			$message = ORM::factory('message')->find($message_id);
			if ($message->loaded)
			{
				$messagelock = ORM::factory('message_lock')
					->where('message_id', $message_id)
					->where('user_id', $user_id)
					->where(' ( ('.$timestamp.' - lock_date) < '.LOCK_PERIOD.' ) ')
					->find();
				if (!$messagelock->loaded)
				{ // Lock Doesn't Exist - Create New One
					$messagelock->message_id = $message_id;
					$messagelock->user_id = $user_id;
					$messagelock->lock_date = $timestamp;
					$messagelock->save();
					
					echo json_encode(array("status"=>"success", "message"=>"Lock Created. This Lock Will be held for 30 minutes or until you release it"));
				}
				else
				{ // Lock Exists - return Error
					echo json_encode(array("status"=>"error", "message"=>"Lock Already Exists For This Message"));
				}
			}
		}
		else
		{
			echo json_encode(array("status"=>"error", "message"=>"Message Doesn't Exist!"));
		}
	}
	
	/**
	* Unlock a message
    */
	function unlock($message_id = 0)
	{
		$this->template = "";
		$this->auto_render = FALSE;
		
		// Is the Admin Logged In? Just Double Check
		$user_id = Auth::instance()->logged_in()
		     ? $_SESSION['auth_user']->id
		     : 0;
		
		if ($message_id && $user_id)
		{
			$message = ORM::factory('message')->find($message_id);
			if ($message->loaded)
			{
				$messagelock = ORM::factory('message_lock')
					->where('message_id', $message_id)
					->where('user_id', $user_id)
					->delete_all();
				
				echo json_encode(array("status"=>"success", "message"=>"Message Unlocked!"));
			}
		}
		else
		{
			echo json_encode(array("status"=>"error", "message"=>"Message Doesn't Exist!"));
		}
	}
	
	/**
	* Delete Old Lock
    */
	private function _remove_locks()
	{
		// Current Time Timestamp
		$timestamp = time();
		ORM::factory('message_lock')
			->where(' ( ('.$timestamp.' - lock_date) > '.LOCK_PERIOD.' ) ')
			->delete_all();
	}

    /**
     * Delete a single message
     */
    public function delete($id = FALSE,$dbtable='message')
    {
		// Get the Service ID and Page to Return To
		$extradir = "";
		$extradir = ( isset($_GET['service_id']) && !empty($_GET['service_id']) )
			? "index/".$_GET['service_id'] : "";
		$extradir .= ( isset($_GET['page']) && !empty($_GET['page']) )
			? "?page=".$_GET['page'] : "";
		$message = ORM::factory($dbtable)->find($id);
		if ($message->loaded)
		{
			$message->message_trash = 1;
			$message->save($id);
		}
		url::redirect(url::base().'admin/messages/index/'.$extradir );
    }

	
	/**
     * Delete a single message
     */
    public function download($service_id = 1, $message_type = 1)
    {
		set_time_limit(60);
		$this->template = "";
		$this->auto_render = FALSE;
		
		if ($message_type == '2')
		{
			$filter = ' message.incident_id = 0 ';
		}
		elseif ($message_type == '3')
		{
			$filter = ' message.incident_id > 0 ';
		}
		else
		{
			$filter = ' 1=1 ';
		}
		
		$filter .= " AND ( CONVERT(message.service_messageid, SIGNED INTEGER) IS NOT NULL AND message.service_messageid > 0 )";
		
		$messages = ORM::factory('message')
			->join('reporter','message.reporter_id','reporter.id')
			->where('service_id', $service_id)
			->where($filter)
			->where('message_trash',0)
			->orderby('message.id', 'ASC')
			->find_all();
			
		$message_csv = "\"ID\",\"PHONE NUMBER\",\"TIME RECEIVED\",\"ORIGINAL MESSAGE (CREOLE)\",\"ORIGINAL MESSAGE (ENGLISH)\",\"GPS COORDINATES (LON/LAT)\",\"ACTIONABLE\"\n";
		foreach ($messages as $message)
		{
			$message_csv .= '"'.$message->service_messageid.'",';
			$message_csv .= '"'.htmlspecialchars($message->message_from).'",';
			$message_csv .= '"'.$message->message_date.'",';
			$message_csv .= '"'.htmlspecialchars(
				str_replace("\r", " ", str_replace("\n", " ", $message->message))).'",';
			$message_csv .= '"'.htmlspecialchars(str_replace("~~~~~~~~~~~~~~~~~", " -- ", 
				str_replace("\r", " ", str_replace("\n", " ", $message->message_detail)))).'",';
			if ($message->location_id !=0 )
			{
				$message_csv .= '"'.$message->location->longitude.','.$message->location->latitude.'",';
			}
			else
			{
				$message_csv .= '"0,0",';
			}
			if ($message->message_actionable)
			{
				$message_csv .= '"YES"'."\n";
			}
			else
			{
				$message_csv .= '"--"'."\n";
			}
			
		}
		
		// Output to browser
		header("Content-type: text/x-csv");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Content-Disposition: attachment; filename=" . time() . ".csv");
		header("Content-Length: " . strlen($message_csv));
		echo $message_csv;
		exit;
	}

    /**
     * Delete selected messages
     */
    private function _deleteMessages($ids,$dbtable='message')
    {
		// Get the Service ID and Page to Return To
		$extradir = "";
		$extradir = ( isset($_GET['service_id']) && !empty($_GET['service_id']) )
			? "index/".$_GET['service_id'] : "";
		$extradir .= ( isset($_GET['page']) && !empty($_GET['page']) )
			? "?page=".$_GET['page'] : "";
       	foreach($ids as $id)
        {
            $message = ORM::factory($dbtable)->find($id);
			if ($message->loaded)
			{
				$message->message_trash = 1;
				$message->save($id);
			}
        }
		url::redirect(url::base().'admin/messages/index/'.$extradir);
    }

    /**
     * Rank selected messages
     */
    private function _rankMessages($ids,$level,$dbtable='message')
    {
        //XXX:get the current page number
    	foreach($ids as $id)
        {
            $msg = ORM::factory($dbtable)->find($id);
            $msg->message_level = $level;
            $msg->save();
        }
    	$extradir = '';
        // url::redirect('admin/messages/'.$extradir);

    }

    /**
	* Lists the Twitter messages.
    */
	function twitter()
	{
		$this->template->content = new View('admin/messages_twitter');
		$this->template->content->title = 'Twitter Messages';

		$this->load_tweets();

		//So far this assumes that selected 'twitter_id's are for deleting
		if (isset($_POST['tweet_id'])) {
			$this->_deleteMessages($_POST['tweet_id'],'twitter');
		}

		//Set Inbox/Outbox filter for query and message tab in view
		//Set default as inbox
		$type = 1;
		$filter = 'tweet_type = 1';
		//Check if outbox
		if (!empty($_GET['type']) && $_GET['type'] == 2){
			$type = 2;
			$filter = 'tweet_type = 2';
		}

		// check, has the form been submitted?
		$form_error = FALSE;
		$form_saved = FALSE;
		$form_action = "";

		// Pagination
		$pagination = new Pagination(array(
			'query_string'   => 'page',
			'items_per_page' => (int) Kohana::config('settings.items_per_page_admin'),
			'total_items'    => ORM::factory('twitter')->where($filter)->count_all()
		));

		//Order by tweet_hashtag first to bring direct reports to the top
		$tweets = ORM::factory('twitter')->where($filter)->where('hide',0)->orderby('tweet_hashtag', 'asc')->orderby('tweet_date', 'desc')->find_all((int) Kohana::config('settings.items_per_page_admin'), $pagination->sql_offset);

		// Populate values for view
		$this->template->content->tweets = $tweets;
		$this->template->content->pagination = $pagination;
		$this->template->content->form_error = $form_error;
		$this->template->content->form_saved = $form_saved;
		$this->template->content->form_action = $form_action;

		// Total Reports
		$this->template->content->total_items = $pagination->total_items;

		// Message Type Tab - Inbox/Outbox
		$this->template->content->type = $type;

		// Javascript Header
		$this->template->js = new View('admin/messages_js');

	}

	/**
	* Collects the twitter messages and loads them into the database
    */
	function load_tweets()
	{
		// Set a timer so Twitter doesn't get requests every page load.
		// Note: We will move this to the fake-cron in the scheduler controller and change this.
		$proceed = 0; // Sanity check. This is just in case $proceed doesn't get set.
		if(!isset($_SESSION['twitter_timer'])) {
			$_SESSION['twitter_timer'] = time();
			$proceed = 1;
		}else{
			$timeCheck = time() - $_SESSION['twitter_timer'];
			if($timeCheck > 0) { //If it has been longer than 300 seconds (5 min)
				$proceed = 1;
				$_SESSION['twitter_timer'] = time(); //Only if we proceed do we want to reset the timer
			}else{
				$proceed = 0;
			}
		}

		if($proceed == 1) { // Grab Tweets

			// Grabbing tweets requires cURL so we will check for that here.
			if (!function_exists('curl_exec'))
			{
				throw new Kohana_Exception('messages.load_tweets.cURL_not_installed');
				return false;
			}

			// Retrieve Current Settings
			$settings = ORM::factory('settings', 1);

			//Perform Hashtag Search
			$hashtags = explode(',',$settings->twitter_hashtags);
			foreach($hashtags as $hashtag){
				$page = 1;
				$have_results = TRUE; //just starting us off as true, although there may be no results
				while($have_results == TRUE && $page <= 5){ //This loop is for pagination of rss results
				$hashtag = trim(str_replace('#','',$hashtag));
					//$twitter_url = 'http://search.twitter.com/search.atom?q=%23'.$hashtag.'&page='.$page;
					$twitter_url = 'http://search.twitter.com/search.json?q=%23'.$hashtag.'&page='.$page;
					$curl_handle = curl_init();
					curl_setopt($curl_handle,CURLOPT_URL,$twitter_url);
					curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2); //Since Twitter is down a lot, set timeout to 2 secs
					curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1); //Set curl to store data in variable instead of print
					$buffer = curl_exec($curl_handle);
					curl_close($curl_handle);
					//$have_results = $this->add_tweets($buffer,$hashtag); //if FALSE, we will drop out of the loop
					$have_results = $this->add_json_tweets($buffer); //if FALSE, we will drop out of the loop
					$page++;
				}
			}

			//Perform Direct Reports Search
			$username = $settings->twitter_username;
			$password = $settings->twitter_password;
			if (!empty($username) && !empty($password))
			{
				$twitter_url = 'http://twitter.com/statuses/replies.json';
				$curl_handle = curl_init();
				curl_setopt($curl_handle,CURLOPT_URL,$twitter_url);
				curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
				curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);
				curl_setopt($curl_handle,CURLOPT_USERPWD,"$username:$password"); //Authenticate!
				$buffer = curl_exec($curl_handle);
				curl_close($curl_handle);
				//$this->add_tweets($buffer,null,$username);
				$this->add_json_tweets($buffer);
			}
		}
	}

	/**
	* Adds tweets to the database.
    * @param string $data - Twitter XML results
    * @param string $hashsearch - null if using auth session, or the hashtag being used to search
    * @param string $username
    */
	function add_tweets($data,$hashsearch = null,$username=''){
		$feed_data = $this->_setup_simplepie( $data ); //Pass this the raw xml data
		if($feed_data->get_item_quantity() != 0){
			foreach($feed_data->get_items(0,50) as $feed_data_item) {
				//Grab tweet data from RSS feed
				$tweet_link = $feed_data_item->get_link();
				$full_date = $feed_data_item->get_date();
				$tweet_date = date("Y-m-d H:i:s",strtotime($full_date));
				if($hashsearch != null){
					$tweet_hashtag = $hashsearch;
					$full_tweet = $feed_data_item->get_title();
					$tweet_from = $feed_data_item->get_author()->get_name();
					//chop off string at "("
					$tweet_from = trim(substr($tweet_from,0,stripos($tweet_from,'(')));
					$tweet_to = ''; // There is no "to" so we make it blank
					$tweet = $full_tweet;
				}else{
					$tweet_hashtag = ''; //not searching using a hashtag
					$full_tweet = $feed_data_item->get_description();
					//Parse tweet for data
					$chop_location = ': @'.$username;
					$cut1 = stripos($full_tweet, $chop_location); //Find the position of the username
					$cut2 = $cut1 + strlen($chop_location) + 1; //Calculate the pos of the start of the tweet
					$tweet_from = substr($full_tweet,0,$cut1);
					$tweet_to = $username;
					$tweet = substr($full_tweet,$cut2);
				}

				if(isset($full_tweet) && !empty($full_tweet)) {
					// We need to check for duplicates.
					// Note: Heave on server.
					$dupe_count = ORM::factory('twitter')->where('tweet_link',$tweet_link)->where('tweet',$tweet)->count_all();
					if ($dupe_count == 0) {
						// Add tweet to database
						$newtweet = new Twitter_Model();
						$newtweet->tweet_from = $tweet_from;
						$newtweet->tweet_to = $tweet_to;
						$newtweet->tweet_hashtag = $tweet_hashtag;
						$newtweet->tweet_link = $tweet_link;
						$newtweet->tweet = $tweet;
						$newtweet->tweet_date = $tweet_date;
						$newtweet->save();
					}
				}
			}
		}else{
			return FALSE; //if there are no items in the feed
		}
		$feed_data->__destruct(); //in the off chance we hit a ton of feeds, we need to clean it out
		return TRUE; //if there were items in the feed
	}

	/**
	* Adds tweets in JSON format to the database and saves the sender as a new
	* Reporter if they don't already exist unless the message is a TWitter Search result
    * @param string $data - Twitter JSON results
    */

	private function add_json_tweets($data)
	{
		$services = new Service_Model();
    	$service = $services->where('service_name', 'Twitter')->find();
	   	if (!$service) {
 		    return false;
	    }
		$tweets = json_decode($data, false);
		if (!$tweets) {
			return false;
		}
		
		if (array_key_exists('results', $tweets)) {
			$tweets = $tweets->{'results'};
		}

		foreach($tweets as $tweet) {
			$tweet_user = null;
			if (array_key_exists('user', $tweet)) {
				$tweet_user = $tweet->{'user'};
			}
			
			//XXX For Twitter Search, should we curl Twitter for a full tweet?
			
    		$reporter = null;
    		if ($tweet_user) {
	    		$reporter_model = new Reporter_Model();
				$reporters = $reporter_model->where('service_id', $service->id)->
				             where('service_userid', $tweet_user->{'id'})->
				             find_all();
				if (count($reporters) < 1) {
					// Add new reporter
		    		$names = explode(' ', $tweet_user->{'name'}, 2);
		    		$last_name = '';
		    		if (count($names) == 2) {
		    			$last_name = $names[1];
		    		}

		    		// get default reporter level (Untrusted)
		    		$levels = new Level_Model();
			    	$default_level = $levels->where('level_weight', 0)->find();

		    		$reporter = new Reporter_Model();
		    		$reporter->service_id       = $service->id;
		    		$reporter->service_userid   = $tweet_user->{'id'};
		    		$reporter->service_account  = $tweet_user->{'screen_name'};
		    		$reporter->reporter_level   = $default_level;
		    		$reporter->reporter_first   = $names[0];
		    		$reporter->reporter_last    = $last_name;
		    		$reporter->reporter_email   = null;
		    		$reporter->reporter_phone   = null;
		    		$reporter->reporter_ip      = null;
		    		$reporter->reporter_date    = date('Y-m-d');
		    		$reporter->save();
	    		} else {
	    			// reporter already exists
	    			$reporter = $reporters[0];
	    		}
	    	}

			if (count(ORM::factory('message')->where('service_messageid', $tweet->{'id'})
			                           ->find_all()) == 0) {
				// Save Tweet as Message
	    		$message = new Message_Model();
	    		$message->parent_id = 0;
	    		$message->incident_id = 0;
	    		$message->user_id = 0;
	    		
	    		if ($reporter) {
	    			$message->reporter_id = $reporter->id;
	    		} else {
	    			$message->reporter_id = 0;
	    		}
	    		
		    	if ($tweet_user) { 
		    		$message->message_from = $tweet_user->{'screen_name'};
	    		} elseif (array_key_exists('from_user', $tweet)) { // Twitter Search tweets
		    		$message->message_from = $tweet->{'from_user'};
	    		}
	    		$message->message_to = null;
	    		$message->message = $tweet->{'text'};
	    		$message->message_detail = null;
	    		$message->message_type = 1; // Inbox
	    		$tweet_date = date("Y-m-d H:i:s",strtotime($tweet->{'created_at'}));
	    		$message->message_date = $tweet_date;
	    		$message->service_messageid = $tweet->{'id'};
	    		$message->save();
    		}
    	}
    	return true;
	}

	/**
	 * Lists the Laconica messages.
     * @param int $page
     */
	function laconica($page = 1)
	{
		$this->template->content = new View('admin/messages_laconica');
		$this->template->content->title = 'Laconica Messages';

		$this->load_laconica_mesgs();

		//So far this assumes that selected 'message_id's are for deleting
        if (isset($_POST['laconica_mesg_id']))
            $this->_deleteMessages($_POST['laconica_mesg_id'],'laconica');

		// Is this an Inbox or Outbox Filter?
		if (!empty($_GET['type']))
		{
			$type = $_GET['type'];

			if ($type == '2')
			{
				$filter = 'laconica_mesg_type = 2';
			}
			else
			{
				$type = "1";
				$filter = 'laconica_mesg_type = 1';
			}
		}
		else
		{
			$type = "1";
			$filter = 'laconica_mesg_type = 1';
		}

		// check, has the form been submitted?
		$form_error = FALSE;
		$form_saved = FALSE;
		$form_action = "";

		// Pagination
		$pagination = new Pagination(array(
			'query_string'    => 'page',
			'items_per_page' => (int) Kohana::config('settings.items_per_page_admin'),
			'total_items'    => ORM::factory('laconica')->where($filter)->count_all()
		));

		$laconica_mesgs = ORM::factory('laconica')->where($filter)->where('hide',0)->orderby('laconica_mesg_date', 'desc')->find_all((int) Kohana::config('settings.items_per_page_admin'), $pagination->sql_offset);

		$this->template->content->laconica_mesgs = $laconica_mesgs;
		$this->template->content->pagination = $pagination;
		$this->template->content->form_error = $form_error;
		$this->template->content->form_saved = $form_saved;
		$this->template->content->form_action = $form_action;

		// Total Reports
		$this->template->content->total_items = $pagination->total_items;

		// Message Type Tab - Inbox/Outbox
		$this->template->content->type = $type;

		// Javascript Header
		$this->template->js = new View('admin/messages_js');

	}

	/**
	 * Collects the laconica messages and loads them into the database
     */
	function load_laconica_mesgs()
	{
		// Set a timer so Twitter doesn't get requests every 2 seconds if the user hits refresh constantly
		$proceed = 0; // Set this as the default in case something wonky happens with the conditional
		if(!isset($_SESSION['laconica_timer'])){
			$_SESSION['laconica_timer'] = time();
			$proceed = 1;
		}else{
			$timeCheck = time() - $_SESSION['laconica_timer'];
			if($timeCheck > 300) { //If it has been longer than 300 seconds (5 min)
				$proceed = 1;
				$_SESSION['laconica_timer'] = time(); //Only if we proceed do we want to reset the timer
			}else{
				$proceed = 0;
			}
		}

		if($proceed == 1) { // Grab Laconica Messages

			// Grabbing Laconica Messages requires cURL so we will check for that here.
			if (!function_exists('curl_exec'))
			{
				throw new Kohana_Exception('messages.load_laconica_mesgs.cURL_not_installed');
				return false;
			}

			// Retrieve Current Settings
			$settings = ORM::factory('settings', 1);

			$username = $settings->laconica_username;
			$password = $settings->laconica_password;
			$laconica_site = $settings->laconica_site;
			$laconica_url = $laconica_site.'/api/statuses/replies.rss';
			$curl_handle = curl_init();
			curl_setopt($curl_handle,CURLOPT_URL,"$laconica_url");
			curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
			curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);
			curl_setopt($curl_handle,CURLOPT_USERPWD,"$username:$password");
			$buffer = curl_exec($curl_handle);
			curl_close($curl_handle);

			$search_username = ': @'.$username;
			$feed_data = $this->_setup_simplepie( $buffer );
			foreach($feed_data->get_items(0,50) as $feed_data_item)
			{
				$full_laconica_mesg = $feed_data_item->get_description();
				$full_date = $feed_data_item->get_date();
				$laconica_mesg_link = $feed_data_item->get_link();

				$cut1 = stripos($full_laconica_mesg, $search_username);
				$cut2 = $cut1 + strlen($search_username) + 1;
				$laconica_mesg_from = substr($full_laconica_mesg,0,$cut1);
				$laconica_mesg_to = $username;
				$laconica_mesg = substr($full_laconica_mesg,$cut2);
				$laconica_mesg_date = date("Y-m-d H:i:s",strtotime($full_date));

				if (isset($full_laconica_mesg) && !empty($full_laconica_mesg))
				{
					// We need to check for duplicates!!!
					// Maybe combination of Title + Date? (Kinda Heavy on the Server :-( )
					$dupe_count = ORM::factory('laconica')->where(
					    'laconica_mesg_link',
					    $laconica_mesg_link)->where('laconica_mesg',
					    $laconica_mesg)->count_all();
					if ($dupe_count == 0) {
						$newitem = new Laconica_Model();
						$newitem->laconica_mesg_from = $laconica_mesg_from;
						$newitem->laconica_mesg_to = $laconica_mesg_to;
						$newitem->laconica_mesg_link = $laconica_mesg_link;
						$newitem->laconica_mesg = $laconica_mesg;
						$newitem->laconica_mesg_date = $laconica_mesg_date;
						$newitem->save();
					}
				}
			}
		}
	}

	/**
	 * setup simplepie
	 * @param string $raw_data
	 */
	private function _setup_simplepie( $raw_data ) {
			$data = new SimplePie();
			$data->set_raw_data( $raw_data );
			$data->enable_cache(false);
			$data->enable_order_by_date(true);
			$data->init();
			$data->handle_content_type();
			return $data;
	}


}
