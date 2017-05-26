<?php
/**
 * @package midcom.services
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * This class is responsible for managing login session, mainly concentrating on
 * DB I/O.
 *
 * Normally, you should not have to work with this class unless you are either
 * writing an authentication front or back end, or a component which includes online
 * status notifications and the like.
 *
 * The single instance of this class can be accessed as
 * midcom::get()->auth->sessionmgr.
 *
 * <b>Checking whether a user is online</b>
 *
 * The is-user-online check requires the user to have the privilege <i>midcom:isonline</i>
 * set on the user which he is trying to check.
 *
 * @package midcom.services
 */
class midcom_services_auth_sessionmgr
{
    /**
     * A list of loaded login sessions, indexed by their session identifier.
     * This is used for authentication purposes.
     *
     * @var midcom_core_login_session_db[]
     */
    private $_loaded_sessions = array();

    /**
     * Once a session has been authenticated, this variable holds the ID of the current
     * login session.
     *
     * Care should be taken when using this variable, as quite sensitive information can
     * be obtained with this session id.
     *
     * @var string
     */
    private $current_session_id;

    /**
     * @var midcom_services_auth
     */
    private $auth;

    /**
     * Currently authenticated midgard_user object
     *
     * @var midgard_user
     */
    private $user;

    /**
     * Currently authenticated midgard_person object
     *
     * @var midgard_person
     */
    private $person;

    /**
     * Simple, currently empty default constructor.
     *
     * @param midcom_services_auth $auth Main authentication instance
     */
    public function __construct(midcom_services_auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Creates a login session using the given arguments. Returns a session identifier.
     * The call will validate the passed credentials and thus authenticate for the given
     * user at the same time, so there is no need to call authenticate_session() after
     * creating it. A failed password check will of course not create a login session.
     *
     * @param string $username The name of the user to store with the session.
     * @param string $password The clear text password to store with the session.
     * @param string $clientip The client IP to which this session is assigned to. This
     *     defaults to the client IP reported by the web server.
     * @return Array An array holding the session identifier in the 'session_id' key and
     *     the associated user in the 'user' key. Failure returns false.
     */
    public function create_login_session($username, $password, $clientip = null)
    {
        if (!$this->_do_midgard_auth($username, $password)) {
            debug_add('Failed to create a new login session: Authentication Failure', MIDCOM_LOG_ERROR);
            return false;
        }

        return $this->create_session($username, $clientip);
    }

    /**
     * Creates a trusted login session using the given arguments. Returns a session identifier.
     * The call will validate the passed credentials and thus authenticate for the given
     * user at the same time, so there is no need to call authenticate_session() after
     * creating it.
     *
     * @param string $username The name of the user to store with the session.
     * @param string $clientip The client IP to which this session is assigned to. This
     *     defaults to the client IP reported by the web server.
     * @return Array An array holding the session identifier in the 'session_id' key and
     *     the associated user in the 'user' key. Failure returns false.
     */
    public function create_trusted_login_session($username, $clientip = null)
    {
        if (!$this->_do_trusted_midgard_auth($username)) {
            debug_add('Failed to create a new login session: Authentication Failure', MIDCOM_LOG_ERROR);
            return false;
        }

        return $this->create_session($username, $clientip);
    }

    /**
     * Creates the session object
     *
     * @param string $username
     * @param string $clientip
     * @return Array An array holding the session identifier in the 'session_id' key and
     *     the associated user in the 'user' key. Failure returns false.
     */
    private function create_session($username, $clientip)
    {
        if (!$user = $this->auth->get_user($this->person)) {
            debug_add("Failed to create a new login session: User ID {$username} is invalid.", MIDCOM_LOG_ERROR);
            return false;
        }

        if (empty($clientip)) {
            $clientip = $_SERVER['REMOTE_ADDR'];
        }

        $session = new midcom_core_login_session_db;
        $session->userid = $user->id;
        $session->username = $user->username;
        $session->clientip = $clientip;
        $session->timestamp = time();
        if (!$session->create()) {
            debug_add('Failed to create a new login session: ' . midcom_connection::get_error_string(), MIDCOM_LOG_ERROR);
            return false;
        }
        $this->current_session_id = $session->guid;
        $this->_loaded_sessions[$session->guid] = $session;
        return array(
            'session_id' => $this->current_session_id,
            'user' => $user
        );
    }

    /**
     * Checks the system for a valid login session matching the passed arguments.
     * It validates the clients' IP, the user ID and the sesion timeout. If a valid
     * session is found, its ID is returned again, you can from now on use this as
     * a token for authentication.
     *
     * This code will implicitly clean up stale sessions for the current
     * user.
     *
     * @param string $sessionid The Session ID to check for.
     * @param midcom_core_user The user for which we should look up the login session.
     * @param string $clientip The client IP to check against, this defaults to the
     *     client IP reported by Apache.
     * @return string The token you can use for authentication or false, in case there
     *     is no valid session.
     */
    public function load_login_session($sessionid, $user, $clientip = null)
    {
        try {
            $session = new midcom_core_login_session_db($sessionid);
        } catch (Exception $e) {
            debug_add('Login session ' . $sessionid . ' failed to load: ' . $e->getMessage(), MIDCOM_LOG_INFO);
            return false;
        }

        if ($session->timestamp < $this->get_timeout()) {
            $session->delete();
            debug_add("The session {$session->guid} (#{$session->id}) has timed out.", MIDCOM_LOG_INFO);
            return false;
        }

        if ($clientip === null) {
            $clientip = $_SERVER['REMOTE_ADDR'];
        }

        if (   midcom::get()->config->get('auth_check_client_ip')
            && $session->clientip != $clientip) {
            debug_add("The session {$session->guid} (#{$session->id}) had mismatching client IP.", MIDCOM_LOG_INFO);
            debug_add("Expected {$session->clientip}, got {$clientip}.");
            return false;
        }

        if ($session->timestamp < time() - midcom::get()->config->get('auth_login_session_update_interval')) {
            // Update the timestamp if previous timestamp is older than specified interval
            $session->timestamp = time();
            if (!$session->update()) {
                debug_add("Failed to update the session {$session->guid} (#{$session->id}) to the current timestamp: " . midcom_connection::get_error_string(), MIDCOM_LOG_INFO);
            }
        }

        $this->_loaded_sessions[$sessionid] = $session;
        return $sessionid;
    }

    private function get_timeout()
    {
        if (!midcom::get()->config->get('auth_login_session_timeout')) {
            return 0;
        }
        return time() - midcom::get()->config->get('auth_login_session_timeout');
    }

    /**
     * Internal helper, which does the actual Midgard authentication.
     *
     * @param string $username The name of the user to authenticate.
     * @param string $password The password of the user to authenticate.
     * @return boolean Indicating success.
     */
    private function _do_midgard_auth($username, $password)
    {
        if ($username == '' || $password == '') {
            debug_add("Failed to authenticate: Username or password is empty.", MIDCOM_LOG_ERROR);
            return false;
        }
        $this->user = midcom_connection::login($username, $password);

        return $this->_load_person();
    }

    /**
     * Internal helper, which does the actual trusted Midgard authentication.
     *
     * @param string $username The name of the user to authenticate.
     * @return boolean Indicating success.
     */
    private function _do_trusted_midgard_auth($username)
    {
        if ($username == '') {
            debug_add("Failed to authenticate: Username is empty.", MIDCOM_LOG_ERROR);
            return false;
        }

        $this->user = midcom_connection::login($username, '', true);

        return $this->_load_person();
    }

    private function _load_person()
    {
        if (!$this->user) {
            debug_add("Failed to authenticate the given user: ". midcom_connection::get_error_string(),
            MIDCOM_LOG_INFO);
            return false;
        }

        $this->person = $this->user->get_person();
        $person_class = midcom::get()->config->get('person_class');
        if (get_class($this->person) != $person_class) {
            // Cast the person object to correct person class
            $this->person = new $person_class($this->person->guid);
        }

        return true;
    }

    /**
     * Authenticate a given session, which must have been loaded
     * previously with load_login_session (this is mandatory).
     *
     * On success, the Auth service main object will automatically be resynced to
     * the authenticated user.
     *
     * If authentication fails, an invalid session is assumed, which will be
     * invalidated and deleted immediately.
     *
     * @param string $sessionid The session identifier to authenticate against.
     * @return boolean Indicating success.
     */
    public function authenticate_session($sessionid)
    {
        if (!array_key_exists($sessionid, $this->_loaded_sessions)) {
            debug_add("The session {$sessionid} has not been loaded yet, cannot authenticate to it.", MIDCOM_LOG_ERROR);
            return false;
        }

        $username = $this->_loaded_sessions[$sessionid]->username;

        if (!$this->_do_trusted_midgard_auth($username)) {
            $this->delete_session($sessionid);
            return false;
        }

        $this->current_session_id = $sessionid;

        return true;
    }

    /**
     * Drop a session which has been previously loaded successfully.
     * Usually, you will use this during logouts.
     *
     * @param string $sessionid The id of the session to invalidate.
     * @return boolean Indicating success.
     */
    function delete_session($sessionid)
    {
        if (!array_key_exists($sessionid, $this->_loaded_sessions)) {
            debug_add('Only sessions which have been previously loaded can be deleted.', MIDCOM_LOG_ERROR);
            return false;
        }

        $session = $this->_loaded_sessions[$sessionid];

        if (!$session->purge()) {
            debug_add("Failed to delete the delete session {$session->guid} (#{$session->id}): " . midcom_connection::get_error_string(), MIDCOM_LOG_INFO);
            return false;
        }

        unset($this->_loaded_sessions[$sessionid]);
        return true;
    }

    /**
     * This function is called by the framework whenever a user's username is updated. It will
     * synchronize all active login sessions of that user to the new username.
     *
     * Access to this function is restricted to midcom_core_account.
     *
     * @param midcom_core_user $user The user object which has been updated.
     * @param string $new The new username.
     */
    function _update_user_username($user, $new)
    {
        if (empty($new)) {
            return;
        }
        $qb = new midgard_query_builder('midcom_core_login_session_db');
        $qb->add_constraint('userid', '=', $user->id);
        $result = $qb->execute();

        foreach ($result as $session) {
            $session->username = $new;
            $session->update();
        }
    }

    public function _delete_user_sessions(midcom_core_user $user)
    {
        // Delete login sessions
        $qb = new midgard_query_builder('midcom_core_login_session_db');
        $qb->add_constraint('userid', '=', $user->id);
        foreach ($qb->execute() as $entry) {
            debug_add("Deleting login session ID {$entry->id} for user {$entry->username} with timestamp {$entry->timestamp}");
            $entry->delete();
        }
    }

    /**
     * Checks the online state of a given user. You require the privilege midcom:isonline
     * for the user you are going to check. The privilege is not granted by default,
     * to allow users full control over their privacy.
     *
     * 'unknown' is returned in cases where you have insufficient permissions.
     *
     * @param midcom_core_user $user The user object which has been updated.
     * @return string One of 'online', 'offline' or 'unknown', indicating the current online
     *     state.
     */
    function is_user_online($user)
    {
        if (!$user->get_storage()->can_do('midcom:isonline')) {
            return 'unknown';
        }

        $qb = new midgard_query_builder('midcom_core_login_session_db');
        $qb->add_constraint('userid', '=', $user->id);
        $qb->add_constraint('timestamp', '>=', $this->get_timeout());
        $result = $qb->execute();

        if (!$result) {
            return 'offline';
        }
        return 'online';
    }

    /**
     * Returns the total number of users online. This does not adhere the isonline check,
     * as there is no information about which users are online.
     *
     * The test is, as usual, heuristic, as it will count users which forgot to log off
     * as long as their session did not expire.
     *
     * @return int The count of users online
     */
    function get_online_users_count()
    {
        $mc = new midgard_collector('midcom_core_login_session_db', 'metadata.deleted', false);
        $mc->set_key_property('userid');
        $mc->add_constraint('timestamp', '>=', $this->get_timeout());
        $mc->execute();

        return count($mc->list_keys());
    }

    /**
     * Extended check for online users. Returns an array of guid=>midcom_core_user pairs of the users
     * which are currently online. This takes privileges into account and will thus only list
     * users which the current user has the privililege to observe.
     *
     * So the difference between get_online_users_count and the size of this result set is the number
     * of invisible users.
     *
     * @return Array List of visible users that are online.
     */
    function get_online_users()
    {
        $mc = new midgard_collector('midcom_core_login_session_db', 'metadata.deleted', false);
        $mc->set_key_property('userid');
        $mc->add_constraint('timestamp', '>=', $this->get_timeout());
        $mc->execute();

        $result = array();
        $query_result = array_keys($mc->list_keys());
        foreach ($query_result as $userid) {
            if (   ($user = $this->auth->get_user($userid))
                && $user->is_online()) {
                $result[$user->guid] = $user;
            }
        }

        return $result;
    }
}
