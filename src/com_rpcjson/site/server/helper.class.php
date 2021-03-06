<?php
/**
 * This file implements the user service for the project Joomla JSON RPC.
 * 
 * @package Joomla.JSON RPC
 * @subpackage Components
 * @link https://github.com/flomll/jsonrpc-joomla
 * @license	GNU/GPL
 *
 * COPYRIGHT
 *
 * Copyright 2012 Stijn Van Campenhout <stijn.vancampenhout@gmail.com>
 * 
 * This file is part of JSON-RPC2PHP.
 *
 * Joomla RPC JSON is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Joomla RPC JSON is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Joomla RPC JSON; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

class rpcjson_helper {

    function get_joomla_version () {
		$version = new JVersion;
		return $version->getShortVersion();
	}

	function is_joomla15 () {
		return (substr(rpcjson_helper::get_joomla_version(), 0, 3) == '1.5');
	}
	
	function get_component_parameter($name, $default = "") {
		static $params = null;

		if($params == null)
		{
			// load component params info
			$params =& JComponentHelper::getParams(RPCJSON_COMPONENT_NAME);
		}

		return $params->get($name, $default);
	}
	
    function login_user ($username, $password, $service) 
    {
		$userId = -1;		$credentials = array('username' => $username, 'password' => $password);
		$options = array('silent' => true);

		$app = JFactory::getApplication();

		// Save our guest session so we can destroy it.
		$session = JFactory::getSession();
		$guestSessionId = $session->getId();

		// Login will create a new session
		$successfulLogin = $app->login($credentials, $options);

		// Destroy guest session row.  Login forks the session and we will end up with a bunch of guest rows if we don't.
        jimport('joomla.database.table');
        $storage = & JTable::getInstance('session');
        $storage->delete($guestSessionId);

		/*
		// We used to do it this way.  Switching for better non-1.5 support.
		// We need the user to be stored in the session.  This initially started when non-1.5 didn't store the created_user_id for categories.

		// Get the global JAuthentication object
		jimport('joomla.user.authentication');
		$auth = & JAuthentication::getInstance();
		$response = $auth->authenticate($credentials, $options);

		if($response->status === JAUTHENTICATE_STATUS_SUCCESS)
		*/
		if($successfulLogin)
		{
			$userId = JUserHelper::getUserId($username);
			$user = JUser::getInstance($userId);

            // FIXME: Add a parameter to the component
            if($service == 'user') {
                $minusergroup = 0;
			    //$minusergroup = JoomlaAdminMobileHelper::getComponentParameter('minusergroup', 25);
			} else if ($service == 'blogger') {
			    $minusergroup = 1;
			    //$minusergroup = JoomlaAdminMobileHelper::getComponentParameter('minusergroup', 25);
			} else {
			    $minusergroup = 2;
			}
			$minusergroup = 2;
			
			if ( rpcjson_helper::is_joomla15() && property_exists($user, "gid") )
			{
				// Joomla! 1.5
				if ($user->gid < $minusergroup)
				{
					$userId = -2;
				}
			} else if (!$user->authorise('core.admin') && !JAccess::check($userId, 'core.manage', RPCJSON_COMPONENT_NAME))
			{
				// Joomla16, we are not a Super Admin and we do not have access to the component
				// Joomla! 1.6
				$userId = -2;
			}
		}



		return $userId;
	}

	function logout_user () {
		$app = JFactory::getApplication();
		$app->logout();
	}
	
	function build_db_query($query, $start, $limit, $where, $order) {	
		if($where != "") {
			$query .= " WHERE ".$where;
		}

		if($order != "") {
			$query .= " ORDER BY ".$order;
		}
        
		if( ($start != "" || $start == 0) && $limit != "") {
			$query .= " LIMIT ".(int)$start.", ".(int)$limit." ";
		}
	    
	    $query .= ";";
	    return $query;
	}
	
	function build_payload($username, $password, $service, $query, $multipleRows, $modifiction = false) 
// 	function build_payload($username, $password, $service, $query, $multipleRows, $checkoutTable = null, $checkoutId = null, $includeCount = false) 
	{
	    $response = new stdClass();
        // User Authentication
//         if(($userId = JoomlaAdminMobileHelper::loginUser($username, $password)) <= 0) {
//             return new xmlrpcresp(0, XMLRPC_ERR_LOGIN_FAILED, JText::_(JoomlaAdminMobileHelper::loginUserError($userId)));
//         }
        // Check the authority of the user. This should be done on every service function.
   	    if( rpcjson_helper::login_user($username, $password, $service) <= 0 ) {
 	        $response->failure = 'Invalid username or password!';
 	        return $response;
 	    }

        // Security check
// 		if($checkoutTable != null && $checkoutId != null)
// 		{
// 			$item = &JTable::getInstance($checkoutTable);
// 
// 			if($item->load($checkoutId))
// 			{
// 				if($item->isCheckedOut($userId)) {
// 					JoomlaAdminMobileHelper::logoutUser();
// 					return new xmlrpcresp(0, XMLRPC_ERR_CHECKOUT_FAILED, JText::_("Checkout Failed"));
// 				}
// 
// 				$item->checkout($userId);
// 			}
// 		}

		$db = &JFactory::getDBO();
		$db->setQuery($query);
		$db->query();
		
		if($modifiction == false) 
		{
 	    	// Add rows number if the 
			$response->count = $db->getNumRows();
		}

        // Load array of data if more then one here.
 	    if($multipleRows == true && $modifiction == false)
 	    {
 	        $response->data = $db->loadObjectList();
 	    } else if($multipleRows == false && $modifiction == false)
 	    {
 	        $response->data = $db->loadObject();
 	    }

        // Error handling for DB
		if( $db->getErrorNum() )
//		if($db->getErrorNum() && JoomlaAdminMobileHelper::getComponentParameter("debug"))
		{
			//JoomlaAdminMobileHelper::respondAndDie($db->getErrorMsg());
			return $db->getErrorMsg();
		}
		
		$response->code = 200; // Return code 200 - successfully proceeded.

        // Log the user out
        rpcjson_helper::logout_user();
		return $response;
	}
	
	function encrypt ($readable_src) {
	    // Load the public key either form a file or from db
	    // FIXME: get key from db
	    $p_file = fopen (JPATH_COMPONENT.DS.'key'.DS.'public.key', "r");
	    $public_key = fread ($p_file, 8192);
	    fclose ($p_file);
	    
	    // Encrypt the text with the public key
	    // FIXME: display error only if debug modus is enabled.
	    if( !openssl_public_encrypt ($readable_src, $crypted, $public_key) ) {
	        echo openssl_error_string ();
	    }

	    return $crypted;
	}
	
	function decrypt ($crypted_src) {
	    // Load the private key either form a file or form db.
	    // FIXME: get key from db
	    $p_file = fopen (JPATH_COMPONENT.DS.'key'.DS.'private.key', "r");
	    $private_key = fread ($p_file, 8192);
	    fclose ($p_file);
	    
	    // Decrypt the text with the private key
	    if( !openssl_private_decrypt ($crypted_src, $decrypted, $private_key) ) {
	        // FIXME: display error only if the debug modus is enabled
	        echo openssl_error_string ();
        }
        
	    return $decrypted;
	}
}
?>