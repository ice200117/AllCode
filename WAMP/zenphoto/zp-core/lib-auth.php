<?php
/**
* functions used in password hashing for zenphoto
 *
 * @package functions
 *
 * An alternate authorization script may be provided to override this script. To do so, make a script that
 * implements the classes declared below. Place the new script inthe <ZENFOLDER>/plugins/alt/ folder. Zenphoto
 * will then will be automatically loaded the alternate script in place of this one.
 *
 * Replacement libraries must implement two classes:
 * 		"Authority" class: Provides the methods used for user authorization and management
 * 			store an instantiation of this class in $_zp_authority.
 *
 * 		Administrator: supports the basic Zenphoto needs for object manipulation of administrators.
 * (You can include this script and extend the classes if that suits your needs.)
 *
 * The global $_zp_current_admin_obj represents the current admin with.
 * The library must instantiate its authority class and store the object in the global $_zp_authority
 * (Note, this library does instantiate the object as described. This is so its classes can
 * be used as parent classes for lib-auth implementations. If auth_zp.php decides to use this
 * library it will instantiate the class and store it into $_zp_authority.
 *
 * The following elements need to be present in any alternate implementation in the
 * array returned by getAdministrators().
 *
 * 		In particular, there should be array elements for:
 * 				'id' (unique), 'user' (unique),	'pass',	'name', 'email', 'rights', 'valid',
 * 				'group', and 'custom_data'
 *
 * 		So long as all these indices are populated it should not matter when and where
 *		the data is stored.
 *
 *		Administrator class methods are required for these elements as well.
 *
 * 		The getRights() method must define at least the rights defined by the method in
 * 		this library.
 *
 * 		The checkAuthorization() method should promote the "most privileged" Admin to
 * 		ADMIN_RIGHTS to insure that there is some user capable of adding users or
 * 		modifying user rights.
 *
 *
 */

require_once(dirname(__FILE__).'/classes.php');

class Zenphoto_Authority {

	var $admin_users = NULL;
	var $admin_groups = NULL;
	var $admin_all = NULL;
	var $rightsset = NULL;
	var $master_user = NULL;
	var $preferred_version = 3;
	var $supports_version = 3;

	/**
	 * class instantiation function
	 *
	 * @return lib_auth_options
	 */
	function Zenphoto_Authority() {
		$lib_auth_extratext = "";
		$salt = 'abcdefghijklmnopqursuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789~!@#$%^*()_+-={}[]|;,.?/';
		$list = range(0, strlen($salt));
		shuffle($list);
		for ($i=0; $i < 30; $i++) {
			$lib_auth_extratext = $lib_auth_extratext . substr($salt, $list[$i], 1);
		}
		setOptionDefault('extra_auth_hash_text', $lib_auth_extratext);
		setOptionDefault('min_password_lenght', 6);
		setOptionDefault('password_pattern', 'A-Za-z0-9   |   ~!@#$%&*_+`-(),.\^\'"/[]{}=:;?\|');
		$sql = 'SELECT * FROM '.prefix('administrators').' WHERE `valid`=1 ORDER BY `rights` DESC, `id` LIMIT 1';
		$master = query_single_row($sql,false);
		if ($master) {
			$this->master_user = $master['user'];
		}
	}

	function getVersion() {
		$v = getOption('libauth_version');
		if (empty($v)) {
			return $this->preferred_version;
		} else {
			return $v;
		}
	}

	/**
	 * Returns the hash of the zenphoto password
	 *
	 * @param string $user
	 * @param string $pass
	 * @return string
	 */
	function passwordHash($user, $pass) {
		$hash = getOption('extra_auth_hash_text');
		$md5 = md5($user . $pass . $hash);
		if (DEBUG_LOGIN) { debugLog("passwordHash($user, $pass)[$hash]:$md5"); }
		return $md5;
	}

	/**
	 * Checks to see if password follows rules
	 * Returns error message if not.
	 *
	 * @param string $pass
	 * @return string
	 */
	function validatePassword($pass) {
		$l = getOption('min_password_lenght');
		if ($l > 0) {
			if (strlen($pass) < $l) return sprintf(gettext('Password must be at least %u characters'), $l);
		}
		$p = getOption('password_pattern');
		if (!empty($p)) {
			$strong = false;
			$p = str_replace('\|', "\t", $p);
			$patterns = explode('|', $p);
			$p2 = '';
			foreach ($patterns as $pat) {
				$pat = trim(str_replace("\t", '|', $pat));
				if (!empty($pat)) {
					$p2 .= '{<em>'.$pat.'</em>}, ';

					$patrn = '';
					foreach (array('0-9','a-z','A-Z') as $try) {
						if (preg_match('/['.$try.']-['.$try.']/', $pat, $r)) {
							$patrn .= $r[0];
							$pat = str_replace($r[0],'',$pat);
						}
					}
					$patrn .= addcslashes($pat,'\\/.()[]^-');
					if (preg_match('/(['.$patrn.'])/', $pass)) {
						$strong = true;
					}
				}
			}
			if (!$strong)	return sprintf(gettext('Password must contain at least one of %s'), substr($p2,0,-2));
		}
		return false;
	}

	/**
	 * Returns text describing password constraints
	 *
	 * @return string
	 */
	function passwordNote() {
		$l = getOption('min_password_lenght');
		$p = getOption('password_pattern');
		$p = str_replace('\|', "\t", $p);
		$c = 0;
		if (!empty($p)) {
			$patterns = explode('|', $p);
			$text = '';
			foreach ($patterns as $pat) {
				$pat = trim(str_replace("\t", '|', $pat));
				if (!empty($pat)) {
					$c++;
					$text .= ', <nobr><strong>{</strong><em>'.html_encode($pat).'</em><strong>}</strong></nobr>';
				}
			}
			$text = substr($text, 2);
		}
		if ($c > 0) {
			if ($l > 0) {
				$msg = '<p class="notebox">'.sprintf(ngettext('<strong>Note:</strong> passwords must be at least %1$u characters long and contain at least one character from %2$s.',
															'<strong>Note</strong>: passwords must be at least %1$u characters long and contain at least one character from each of the following groups: %2$s.', $c), $l, $text).'</p>';;
			} else {
				$msg = '<p class="notebox">'.sprintf(ngettext('<strong>Note</strong>: passwords must contain at least one character from %s.',
															'<strong>Note</strong>: passwords must contain at least one character from each of the following groups: %s.', $c), $text).'</p>';
			}
		} else {
			if ($l > 0) {
				$msg = sprintf(gettext('<strong>Note</strong>: passwords must be at least %u characters long.'), $l);
			} else {
				$msg = '';
			}
		}
		return $msg;
	}

	/**
	 * Returns an array of admin users, indexed by the userid and ordered by "privileges"
	 *
	 * The array contains the id, hashed password, user's name, email, and admin privileges
	 *
	 * @param string $what: 'all' for everything, 'users' for just users 'groups' for groups and templates
	 * @return array
	 */
	function getAdministrators($what='users') {
		if (is_null($this->admin_users)) {
			$this->admin_all = $this->admin_groups = $this->admin_users = array();
			$sql = 'SELECT * FROM '.prefix('administrators').' ORDER BY `rights` DESC, `id`';
			$admins = query_full_array($sql, false);
			if ($admins !== false) {
				foreach($admins as $user) {
					$this->admin_all[$user['id']] = $user;
					if ($user['valid']) {
						$this->admin_users[$user['id']] = $user;
					} else {
						$this->admin_groups[$user['id']] = $user;
					}
				}
			}
		}
		switch ($what) {
			case 'users':
				return $this->admin_users;
			case 'groups':
				return $this->admin_groups;
			default:
				return $this->admin_all;
		}
	}

	/**
	 * Returns an admin object from the $pat:$criteria
	 * @param string $match
	 * @param string $criteria
	 * @return Zenphoto_Administrator
	 */
	function getAnAdmin($match,$criteria) {
		$sql = 'SELECT * FROM '.prefix('administrators').' WHERE '.$match.db_quote($criteria).' LIMIT 1';
		$admin = query_single_row($sql,false);
		if ($admin) {
			return $this->newAdministrator($admin['user'], 1);
		} else {
			return NULL;
		}
	}

	/**
	 * Retuns the administration rights of a saved authorization code
	 * Will promote an admin to ADMIN_RIGHTS if he is the most privileged admin
	 *
	 * @param string $authCode the md5 code to check
	 *
	 * @return bit
	 */
	function checkAuthorization($authCode) {
		global $_zp_current_admin_obj;
		if (DEBUG_LOGIN) { debugLogBacktrace("checkAuthorization($authCode)");	}

		$admins = $this->getAdministrators();
		if (DEBUG_LOGIN) { debugLogArray("checkAuthorization: admins",$admins);	}
		$reset_date = getOption('admin_reset_date');
		if ((count($admins) == 0) || empty($reset_date)) {
			$_zp_current_admin_obj = NULL;
			if (DEBUG_LOGIN) { debugLog("checkAuthorization: no admin or reset request"); }
			return ADMIN_RIGHTS; //no admins or reset request
		}

		if (empty($authCode)) return 0; //  so we don't "match" with an empty password
		$_zp_current_admin_obj = null;
		$rights = 0;
		$user = $this->getAnAdmin('`pass`=', $authCode);
		if (is_object($user)) {
			$_zp_current_admin_obj = $user;
			$rights = $user->getRights();
			if (DEBUG_LOGIN) { debugLog(sprintf('checkAuthorization: from $authcode %X',$rights));	}
			return $rights;
		}

		$_zp_current_admin_obj = null;
		if (DEBUG_LOGIN) { debugLog("checkAuthorization: no match");	}
		return 0; // no rights
	}

	/**
	 * Checks a logon user/password against the list of admins
	 *
	 * Returns true if there is a match
	 *
	 * @param string $user
	 * @param string $pass
	 * @param bool $admin_login will be true if the login for the backend. If false, it is a guest login beging checked for admin credentials
	 * @return bool
	 */
	function checkLogon($user, $pass, $admin_login) {
		$admins = $this->getAdministrators();
		$success = false;
		$md5 = $this->passwordHash($user, $pass);
		foreach ($admins as $admin) {
			if ($admin['valid']) {
				if (DEBUG_LOGIN) { debugLogArray('checking:',$admin); }
				if ($admin['user'] == $user) {
					if ($admin['pass'] == $md5) {
						$success = $this->checkAuthorization($md5);
						break;
					}
				}
			}
		}
		return $success;
	}

	/**
	 * Returns the email addresses of the Admin with ADMIN_USERS rights
	 *
	 * @param bit $rights what kind of admins to retrieve
	 * @return array
	 */
	function getAdminEmail($rights=NULL) {
		if (is_null($rights)) {
			$rights = ADMIN_RIGHTS;
		}
		$emails = array();
		$admins = $this->getAdministrators();
		foreach ($admins as $user) {
			if (($user['rights'] & $rights)  && is_valid_email_zp($user['email'])) {
				$name = $user['name'];
				if (empty($name)) {
					$name = $user['user'];
				}
				$emails[$name] = $user['email'];
			}
		}
		return $emails;
	}

	/**
	 * Migrates credentials
	 *
	 * @param int $oldversion
	 */
	function migrateAuth($to) {
		if ($to > $this->supports_version || $to < $this->preferred_version-1) {
			trigger_error(sprintf(gettext('Cannot migrate rights to version %1$s (Zenphoto_Authority supports only %2$s and %3$s.)'),$to,$_zp_authority->supports_version,$this->preferred_version), E_USER_NOTICE);
			return false;
		}
		$success = true;
		$oldversion = $this->getVersion();
		setOption('libauth_version',$to);
		$this->admin_users = array();
		$sql = "SELECT * FROM ".prefix('administrators')."ORDER BY `rights` DESC, `id`";
		$admins = query_full_array($sql, false);
		if (count($admins)>0) { // something to migrate
			$oldrights = array();
			foreach ($this->getRights($oldversion) as $key=>$right) {
				$oldrights[$key] = $right['value'];
			}
			$currentrights = $this->getRights($to);
			foreach($admins as $user) {
				$update = false;
				$rights = $user['rights'];
				$newrights = 0;
				foreach ($currentrights as $key=>$right) {
					if ($right['display']) {
						if (array_key_exists($key, $oldrights) && $rights & $oldrights[$key]) {
							$newrights = $newrights | $right['value'];
						}
					}
				}
				if ($to == 3 && $oldversion < 3) {
					if ($rights & $oldrights['VIEW_ALL_RIGHTS']) {
						$updaterights = $currentrights['VIEW_ALBUMS_RIGHTS']['value'] | $currentrights['VIEW_PAGES_RIGHTS']['value'] |
													$currentrights['VIEW_NEWS_RIGHTS']['value'] | $currentrights['VIEW_SEARCH_RIGHTS']['value']	|
													$currentrights['VIEW_GALLERY_RIGHTS']['value'] | $currentrights['VIEW_FULLIMAGE_RIGHTS']['value'];
						$newrights = $newrights | $updaterights;
					}
				}
				if ($oldversion == 3 && $to < 3) {
					if ($oldrights['VIEW_ALBUMS_RIGHTS'] || $oldrights['VIEW_PAGES_RIGHTS'] || $oldrights['VIEW_NEWS_RIGHTS']) {
						$newrights = $newrights | $currentrights['VIEW_ALL_RIGHTS']['value'];
					}
				}
				if ($oldversion == 1) {	// need to migrate zenpage rights
					if ($rights & $oldrights['ZENPAGE_RIGHTS']) {
						$newrights = $newrights | ZENPAGE_PAGES_RIGHTS | ZENPAGE_NEWS_RIGHTS | FILES_RIGHTS;
					}
				}
				if ($to == 1) {
					if ($rights & ($oldrights['ZENPAGE_PAGES_RIGHTS'] | $oldrights['ZENPAGE_NEWS_RIGHTS'] | $oldrights['FILES_RIGHTS'])) {
						$newrights = $newrights | ZENPAGE_RIGHTS;
					}
				}

				$sql = 'UPDATE '.prefix('administrators').' SET `rights`='.$newrights.' WHERE `id`='.$user['id'];
				$success = $success && query($sql);
			} // end loop
		}
		return $success;
	}

	/**
	 * Updates a field in admin record(s)
	 *
	 * @param string $field name of the field
	 * @param mixed $value what to store
	 * @param array $constraints field value pairs for constraining the update
	 * @return mixed Query result
	 */
	function updateAdminField($field, $value, $constraints) {
		$where = '';
		foreach ($constraints as $field=>$clause) {
			if (!empty($where)) $where .= ' AND ';
			$where .= '`'.$field.'`='.db_quote($clause);
		}
		if (is_null($value)) {
			$value = 'NULL';
		} else {
			$value = '"'.$value.'"';
		}
		$sql = 'UPDATE '.prefix('administrators').' SET `'.$field.'`='.$value.' WHERE '.$where;
		return query($sql);
	}

	/**
	 * Instantiates and returns administrator object
	 * @param $name
	 * @param $valid
	 * @return object
	 */
	function newAdministrator($name, $valid=1) {
		$user = new Zenphoto_Administrator($name, $valid);
		if ($valid && $name == $this->master_user) {
			$user->setRights($user->getRights() | ADMIN_RIGHTS);
			$user->master = true;
		}
		return $user;
	}

	/**
	 * Returns an array of the rights definitions for $version (default returns current version rights)
	 *
	 * @param $version
	 */
	function getRights($version=NULL) {
		$rightsset = $this->rightsset;
		if (!empty($version) || is_null($rightsset)) {
			if (empty($version)) {
				$v = $this->getVersion();
			} else {
				$v = $version;
			}
			switch ($v) {
				case 1:
					$rightsset = array(	'NO_RIGHTS' => array('value'=>2,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),
															'OVERVIEW_RIGHTS' => array('value'=>4,'name'=>gettext('Overview'),'set'=>'','display'=>true,'hint'=>''),
															'VIEW_ALL_RIGHTS' => array('value'=>8,'name'=>gettext('View all'),'set'=>'','display'=>true,'hint'=>''),
															'UPLOAD_RIGHTS' => array('value'=>16,'name'=>gettext('Upload'),'set'=>'','display'=>true,'hint'=>''),
															'POST_COMMENT_RIGHTS'=> array('value'=>32,'name'=>gettext('Post comments'),'set'=>'','display'=>true,'hint'=>''),
															'COMMENT_RIGHTS' => array('value'=>64,'name'=>gettext('Comments'),'set'=>'','display'=>true,'hint'=>''),
															'ALBUM_RIGHTS' => array('value'=>256,'name'=>gettext('Album'),'set'=>'','display'=>true,'hint'=>''),
															'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>512,'name'=>gettext('Manage all albums'),'set'=>'','display'=>true,'hint'=>''),
															'THEMES_RIGHTS' => array('value'=>1024,'name'=>gettext('Themes'),'set'=>'','display'=>true,'hint'=>''),
															'ZENPAGE_RIGHTS' => array('value'=>2049,'name'=>gettext('Zenpage'),'set'=>'','display'=>true,'hint'=>''),
															'TAGS_RIGHTS' => array('value'=>4096,'name'=>gettext('Tags'),'set'=>'','display'=>true,'hint'=>''),
															'OPTIONS_RIGHTS' => array('value'=>8192,'name'=>gettext('Options'),'set'=>'','display'=>true,'hint'=>''),
															'ADMIN_RIGHTS' => array('value'=>65536,'name'=>gettext('Admin'),'set'=>'','display'=>true,'hint'=>''));
					break;
				case 2:
					$rightsset = array(	'NO_RIGHTS' => array('value'=>1,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),
															'OVERVIEW_RIGHTS' => array('value'=>pow(2,2),'name'=>gettext('Overview'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view the admin overview page.')),
															'VIEW_ALL_RIGHTS' => array('value'=>pow(2,4),'name'=>gettext('View all'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view all of the gallery regardless of protection of the page. Without this right, the user can view only public ones and those checked in his managed object lists or as granted by View Search or View Gallery.')),
															'UPLOAD_RIGHTS' => array('value'=>pow(2,6),'name'=>gettext('Upload'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may upload to the albums for which they have management rights.')),
															'POST_COMMENT_RIGHTS'=> array('value'=>pow(2,8),'name'=>gettext('Post comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('When the comment_form plugin is used for comments and its "Only members can comment" option is set, only users with this right may post comments.')),
															'COMMENT_RIGHTS' => array('value'=>pow(2,10),'name'=>gettext('Comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make comments tab changes.')),
															'ALBUM_RIGHTS' => array('value'=>pow(2,12),'name'=>gettext('Albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may access the "albums" tab to make changes.')),
															'ZENPAGE_PAGES_RIGHTS' => array('value'=>pow(2,14),'name'=>gettext('Pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage pages.')),
															'ZENPAGE_NEWS_RIGHTS' => array('value'=>pow(2,16),'name'=>gettext('News'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage articles and categories.')),
															'FILES_RIGHTS' => array('value'=>pow(2,18),'name'=>gettext('Files'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Allows the user access to the "filemanager" located on the upload: files sub-tab.')),
															'MANAGE_ALL_PAGES_RIGHTS' => array('value'=>pow(2,20),'name'=>gettext('Manage all pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage page.')),
															'MANAGE_ALL_NEWS_RIGHTS' => array('value'=>pow(2,22),'name'=>gettext('Manage all news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage news article or category.')),
															'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>pow(2,24),'name'=>gettext('Manage all albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any album in the gallery.')),
															'THEMES_RIGHTS' => array('value'=>pow(2,26),'name'=>gettext('Themes'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make themes related changes. These are limited to the themes associated with albums checked in their managed albums list.')),
															'TAGS_RIGHTS' => array('value'=>pow(2,28),'name'=>gettext('Tags'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make additions and changes to the set of tags.')),
															'OPTIONS_RIGHTS' => array('value'=>pow(2,29),'name'=>gettext('Options'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make changes on the options tabs.')),
															'ADMIN_RIGHTS' => array('value'=>pow(2,30),'name'=>gettext('Admin'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('The master privilege. A user with "Admin" can do anything. (No matter what his other rights might indicate!)')));
					break;
				case 3:
					$rightsset = array(	'NO_RIGHTS' => array('value'=>1,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),

															'OVERVIEW_RIGHTS' => array('value'=>pow(2,2),'name'=>gettext('Overview'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may view the admin overview page.')),

															'VIEW_GALLERY_RIGHTS' => array('value'=>pow(2,4),'name'=>gettext('View gallery'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view otherwise protected generic gallery pages.')),
															'VIEW_SEARCH_RIGHTS' => array('value'=>pow(2,5),'name'=>gettext('View search'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view search pages even if password protected.')),
															'VIEW_FULLIMAGE_RIGHTS' => array('value'=>pow(2,6),'name'=>gettext('View fullimage'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may view all full sized (raw) images.')),
															'VIEW_NEWS_RIGHTS' => array('value'=>pow(2,7),'name'=>gettext('View news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users with this right may view all zenpage news articles.')),
															'VIEW_PAGES_RIGHTS' => array('value'=>pow(2,8),'name'=>gettext('View pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users with this right may view all zenpage pages.')),
															'VIEW_ALBUMS_RIGHTS' => array('value'=>pow(2,9),'name'=>gettext('View albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may view all albums (and their images).')),

															'POST_COMMENT_RIGHTS'=> array('value'=>pow(2,11),'name'=>gettext('Post comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('When the comment_form plugin is used for comments and its "Only members can comment" option is set, only users with this right may post comments.')),
															'COMMENT_RIGHTS' => array('value'=>pow(2,12),'name'=>gettext('Comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make comments tab changes.')),
															'UPLOAD_RIGHTS' => array('value'=>pow(2,13),'name'=>gettext('Upload'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may upload to the albums for which they have management rights.')),

															'ZENPAGE_NEWS_RIGHTS' => array('value'=>pow(2,15),'name'=>gettext('News'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage articles and categories.')),
															'ZENPAGE_PAGES_RIGHTS' => array('value'=>pow(2,16),'name'=>gettext('Pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage pages.')),
															'FILES_RIGHTS' => array('value'=>pow(2,17),'name'=>gettext('Files'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Allows the user access to the "filemanager" located on the upload: files sub-tab.')),
															'ALBUM_RIGHTS' => array('value'=>pow(2,18),'name'=>gettext('Albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may access the "albums" tab to make changes.')),

															'MANAGE_ALL_NEWS_RIGHTS' => array('value'=>pow(2,21),'name'=>gettext('Manage all news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage news article or category.')),
															'MANAGE_ALL_PAGES_RIGHTS' => array('value'=>pow(2,22),'name'=>gettext('Manage all pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage page.')),
															'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>pow(2,23),'name'=>gettext('Manage all albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any album in the gallery.')),

															'THEMES_RIGHTS' => array('value'=>pow(2,26),'name'=>gettext('Themes'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make themes related changes. These are limited to the themes associated with albums checked in their managed albums list.')),

															'TAGS_RIGHTS' => array('value'=>pow(2,28),'name'=>gettext('Tags'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make additions and changes to the set of tags.')),
															'OPTIONS_RIGHTS' => array('value'=>pow(2,29),'name'=>gettext('Options'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make changes on the options tabs.')),
															'ADMIN_RIGHTS' => array('value'=>pow(2,30),'name'=>gettext('Admin'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('The master privilege. A user with "Admin" can do anything. (No matter what his other rights might indicate!)')));
					break;
			}
			$allrights = 0;
			foreach ($rightsset as $key=>$right) {
				$allrights = $allrights | $right['value'];
			}
			$rightsset['ALL_RIGHTS'] =	array('value'=>$allrights,'name'=>gettext('All rights'),'display'=>false);
			$rightsset['DEFAULT_RIGHTS'] =	array('value'=>$rightsset['OVERVIEW_RIGHTS']['value']+$rightsset['POST_COMMENT_RIGHTS']['value'],'name'=>gettext('Default rights'),'display'=>false);
			if (isset($rightsset['VIEW_ALL_RIGHTS']['value'])) {
				$rightsset['DEFAULT_RIGHTS']['value'] = $rightsset['DEFAULT_RIGHTS']['value']|$rightsset['VIEW_ALL_RIGHTS']['value'];
			} else {
				$rightsset['DEFAULT_RIGHTS']['value'] = $rightsset['DEFAULT_RIGHTS']|$rightsset['VIEW_ALBUMS_RIGHTS']['value']|
																			 $rightsset['VIEW_PAGES_RIGHTS']['value']|$rightsset['VIEW_NEWS_RIGHTS']['value']|
																			 $rightsset['VIEW_SEARCH_RIGHTS']['value']|$rightsset['VIEW_GALLERY_RIGHTS']['value'];
			}
			$rightsset = sortMultiArray($rightsset,'value',true,false,false);
			if (empty($version)) {
				$this->rightsset = $rightsset;
			}
		}
		return $rightsset;
	}

	/**
	 * Declares options used by lib-auth
	 *
	 * @return array
	 */
	function getOptionsSupported() {
		return array(	gettext('Minimum password length:') => array('key' => 'min_password_lenght', 'type' => OPTION_TYPE_TEXTBOX,
										'desc' => gettext('Minimum number of characters a password must contain.')),
		gettext('Password characters:') => array('key' => 'password_pattern', 'type' => OPTION_TYPE_CLEARTEXT,
										'desc' => gettext('Passwords must contain at least one of the characters from each of the groups. Groups are separated by "|". (Use "\|" to represent the "|" character in the groups.)'))
		);
	}
}

class Zenphoto_Administrator extends PersistentObject {

	/**
	 * This is a simple class so that we have a convienient "handle" for manipulating Administrators.
	 *
	 * NOTE: one should use the Zenphoto_Authority newAdministrator() method rather than directly instantiationg
	 * an administrator object
	 *
	 */
	var $objects = NULL;
	var $master = false;	//	will be set to true if this is the inherited master user

	/**
	 * Constructor for an Administrator
	 *
	 * @param string $userid.
	 * @return Administrator
	 */
	function Zenphoto_Administrator($user, $valid) {
		parent::PersistentObject('administrators', array('user' => $user, 'valid'=>$valid), NULL, false, empty($user));
	}

	function getID() {
		return $this->get('id');
	}

	function setPass($pwd) {
		global $_zp_authority;
		$msg = $_zp_authority->validatePassword($pwd);
		if (!empty($msg)) return $msg;	// password validation failure
		$pwd = $_zp_authority->passwordHash($this->getUser(),$pwd);
		$this->set('pass', $pwd);
		return false;
	}
	function getPass() {
		return $this->get('pass');
	}

	function setName($admin_n) {
		$this->set('name', $admin_n);
	}
	function getName() {
		return $this->get('name');
	}

	function setEmail($admin_e) {
		$this->set('email', $admin_e);
	}
	function getEmail() {
		return $this->get('email');
	}

	function setRights($rights) {
		$this->set('rights', $rights);
	}
	function getRights() {
		return $this->get('rights');
	}

	function setObjects($objects) {
		$this->objects = $objects;
	}
	function getObjects($what=NULL) {
		if (is_null($this->objects)) {
			$this->objects = array();
			if (!$this->transient) {
				$this->objects = populateManagedObjectsList(NULL,$this->getID());
			}
		}
		if (empty($what)) {
			return $this->objects;
		}
		$result = array();
		foreach ($this->objects as $object) {
			if ($object['type'] == $what) {
				$result[] = $object['data'];
			}
		}
		return $result;
	}

	function setCustomData($custom_data) {
		$this->set('custom_data', $custom_data);
	}
	function getCustomData() {
		return $this->get('custom_data');
	}

	function setValid($valid) {
		$this->set('valid', $valid);
	}
	function getValid() {
		return $this->get('valid');
	}

	function setGroup($group) {
		$this->set('group', $group);
	}
	function getGroup() {
		return $this->get('group');
	}

	function setUser($user) {
		$this->set('user', $user);
	}
	function getUser() {
		return $this->get('user');
	}

	function setQuota($v) {
		$this->set('quota',$v);
	}
	function getQuota() {
		return $this->get('quota');
	}

	function getLanguage() {
		return $this->get('language');
	}

	function setLanguage($locale) {
		$this->set('language',$locale);
	}

	function save() {
		if (DEBUG_LOGIN) { debugLogVar("Zenphoto_Administrator->save()", $this); }
		$objects = $this->getObjects();
		$gallery = new Gallery();
		parent::save();
		$id = $this->getID();
		if (is_array($objects)) {
			$sql = "DELETE FROM ".prefix('admin_to_object').' WHERE `adminid`='.$id;
			$result = query($sql);
			foreach ($objects as $object) {
				if (array_key_exists('edit',$object)) {
					$edit = $object['edit'];
				} else {
					$edit = 32767;
				}
				switch ($object['type']) {
					case 'album':
						$album = new Album($gallery, $object['data']);
						$albumid = $album->getAlbumID();
						$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $albumid, 'album', $edit)";
						$result = query($sql);
						break;
					case 'pages':
						$sql = 'SELECT * FROM '.prefix('pages').' WHERE `titlelink`='.db_quote($object['data']);
						$result = query_single_row($sql);
						if (is_array($result)) {
							$objectid = $result['id'];
							$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $objectid, 'pages', $edit)";
							$result = query($sql);
						}
						break;
					case 'news':
						$sql = 'SELECT * FROM '.prefix('news_categories').' WHERE `titlelink`='.db_quote($object['data']);
						$result = query_single_row($sql);
						if (is_array($result)) {
							$objectid = $result['id'];
							$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $objectid, 'news', $edit)";
							$result = query($sql);
						}
						break;
				}
			}
		}
	}

	function remove() {
		$id = $this->getID();
		if (parent::remove()) {
			$sql = "DELETE FROM ".prefix('admin_to_object')." WHERE `adminid`=$id";
			$result = query($sql);
		} else {
			return false;
		}
		return $result;
	}

}

?>