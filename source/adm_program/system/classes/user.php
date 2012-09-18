<?php
/******************************************************************************
 * Class handle role rights, cards and other things of users
 *
 * Copyright    : (c) 2004 - 2012 The Admidio Team
 * Homepage     : http://www.admidio.org
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Diese Klasse dient dazu ein Userobjekt zu erstellen.
 * Ein User kann ueber diese Klasse in der Datenbank verwaltet werden
 *
 * Beside the methods of the parent class there are the following additional methods:
 *
 * checkPassword($password) - check password against stored hash    
 * deleteUserFieldData()    - delete all user data of profile fields; 
 *                            user record will not be deleted
 * getListViewRights()  - Liefert ein Array mit allen Rollen und der 
 *                        Berechtigung, ob der User die Liste einsehen darf
 *                      - aehnlich getProperty, allerdings suche ueber usf_id
 * getVCard()           - Es wird eine vCard des Users als String zurueckgegeben
 * setRoleMembership($roleId, $startDate = DATE_NOW, $endDate = '9999-12-31', $leader = '')
 *                      - set a role membership for the current user
 *                        if memberships to this user and role exists within the period than merge them to one new membership
 * viewProfile          - Ueberprueft ob der User das Profil eines uebrgebenen
 *                        Users einsehen darf
 * viewRole             - Ueberprueft ob der User eine uebergebene Rolle(Liste)
 *                        einsehen darf
 * isWebmaster()        - gibt true/false zurueck, falls der User Mitglied der
 *                        Rolle "Webmaster" ist
 *
 *****************************************************************************/

require_once(SERVER_PATH. '/adm_program/system/classes/table_users.php');

class User extends TableUsers
{
    protected $webmaster;

    public $mProfileFieldsData; 		// object with current user field structure
    public $roles_rights = array(); // Array ueber alle Rollenrechte mit dem entsprechenden Status des Users
    protected $list_view_rights = array(); // Array ueber Listenrechte einzelner Rollen => Zugriff nur über getListViewRights()
    protected $role_mail_rights = array(); // Array ueber Mailrechte einzelner Rollen
    protected $rolesMembership  = array(); // Array with all roles who the user is assigned
    protected $rolesMembershipLeader = array(); // Array with all roles who the user is assigned and is leader (key = role_id; value = rol_leader_rights)

    // Konstruktor
    public function __construct(&$db, $userFields, $usr_id = 0)
    {
		$this->mProfileFieldsData = clone $userFields; // create explicit a copy of the object (param is in PHP5 a reference)
        parent::__construct($db, $usr_id);
    }

    // check password against stored hash    
    public function checkPassword($password)
    {
        // if password is stored with phpass hash, then use phpass
        if(substr($this->getValue('usr_password'), 0, 1) == '$')
        {
            $passwordHasher = new PasswordHash(9, true);
            if($passwordHasher->CheckPassword($password, $this->getValue('usr_password')) == true)
            {
                return true;
            }
        }
        // if password is stored the old was then use md5
        elseif(md5($password) == $this->getValue('usr_password'))
        {
            $this->setValue('usr_password', $password);
            return true;
        }
        return false;
    }
	
	/** Insert a record in table adm_registrations and creates a valid registration 
	 *  entry for the current user for the organization of the parameter
	 *  @param $organizationId The id of the organization for which the user should be registered
	 *  @return Returns @b true if the registration was succesful
	 */
	public function insertRegistration($organizationId)
	{
		if($organizationId > 0 && is_numeric($organizationId))
		{
			// insert a record in registration table
			$Registration = new TableAccess($this->db, TBL_REGISTRATIONS, 'reg');
			$Registration->setValue('reg_org_id', $organizationId);
			$Registration->setValue('reg_usr_id', $this->getValue('usr_id'));
			$Registration->setValue('reg_timestamp', DATETIME_NOW);
			$Registration->save();
			
			$this->setValue('usr_valid', 0);
			$this->save();
			return true;
		}
		return false;
	}
	
	/** Deletes the registration record and set the user to valid. So the registration was accepted and
	 *  if he is assigned to a role than he can login for the organization of the parameter
	 *  @param $organizationId The id of the organization for which the user should be registered
	 *  @return Returns @b true if the registration was succesful
	 */
	public function acceptRegistration($organizationId)
	{
		if($organizationId > 0 && is_numeric($organizationId))
		{
			// delete registration record in registration table
			$Registration = new TableAccess($this->db, TBL_REGISTRATIONS, 'reg');
			$Registration->readDataByColumns(array('reg_org_id' => $organizationId, 'reg_usr_id' => $this->getValue('usr_id')));
			$Registration->delete();
			
			$this->setValue('usr_valid', 1);
			$this->save();
			return true;
		}
		return false;		
	}
	
	/** Deletes the registration record and if the user is not valid and has no other registration
     *  than the whole user record will be deleted	
	 *  @param $organizationId The id of the organization for which the user registration should be deleted
	 *  @return Returns @b true if the registration was succesful deleted
	 */
	public function removeRegistration($organizationId)
	{
		if($organizationId > 0 && is_numeric($organizationId))
		{
			// delete registration record in registration table
			$Registration = new TableAccess($this->db, TBL_REGISTRATIONS, 'reg');
			$Registration->readDataByColumns(array('reg_org_id' => $organizationId, 'reg_usr_id' => $this->getValue('usr_id')));
			$Registration->delete();
			
			// if user is not valid and has no other registrations 
			// than delete user because he has no use for the system
			if($this->getValue('usr_valid') == 0)
			{
				$sql = 'SELECT reg_id FROM '.TBL_REGISTRATIONS.' WHERE reg_usr_id = '.$this->getValue('usr_id');
				$this->db->query($sql);

				if($this->db->num_rows() == 0)
				{
					$this->delete();
				}
			}
			return true;
		}
		return false;		
	}

    // Methode prueft, ob der User das uebergebene Rollenrecht besitzt und setzt das Array mit den Flags,
    // welche Rollen der User einsehen darf
    public function checkRolesRight($right = '')
    {
        global $gL10n;

        if($this->getValue('usr_id') > 0)
        {
            if(count($this->roles_rights) == 0)
            {
                global $gCurrentOrganization;
                $tmp_roles_rights  = array('rol_assign_roles'  => '0', 'rol_approve_users' => '0',
                                           'rol_announcements' => '0', 'rol_dates' => '0',
                                           'rol_download'      => '0', 'rol_edit_user' => '0',
                                           'rol_guestbook'     => '0', 'rol_guestbook_comments' => '0',
                                           'rol_mail_to_all'   => '0',
                                           'rol_photo'         => '0', 'rol_profile' => '0',
                                           'rol_weblinks'      => '0', 'rol_all_lists_view' => '0');

                // Alle Rollen der Organisation einlesen und ggf. Mitgliedschaft dazu joinen
                $sql = 'SELECT *
                          FROM '. TBL_CATEGORIES. ', '. TBL_ROLES. '
                          LEFT JOIN '. TBL_MEMBERS. '
                            ON mem_usr_id  = '. $this->getValue('usr_id'). '
                           AND mem_rol_id  = rol_id
                           AND mem_begin  <= \''.DATE_NOW.'\'
                           AND mem_end     > \''.DATE_NOW.'\'
                         WHERE rol_valid   = 1
                           AND rol_cat_id  = cat_id
                           AND (  cat_org_id = '. $gCurrentOrganization->getValue('org_id').' 
                               OR cat_org_id IS NULL ) ';
                $this->db->query($sql);

                while($row = $this->db->fetch_array())
                {
					if($row['mem_leader'] == 1)
					{
						// if user is leader in this role than add role id and leader rights to array
						$this->rolesMembershipLeader[$row['rol_id']] = $row['rol_leader_rights'];
					}
					
                    // Rechte nur beruecksichtigen, wenn auch Rollenmitglied
                    if($row['mem_usr_id'] > 0)
                    {
						// add role to membership array
						$this->rolesMembership[] = $row['rol_id'];

                        // Rechte der Rollen in das Array uebertragen,
                        // falls diese noch nicht durch andere Rollen gesetzt wurden
                        foreach($tmp_roles_rights as $key => $value)
                        {
                            if($value == '0' && $row[$key] == '1')
                            {
                                $tmp_roles_rights[$key] = '1';
                            }
                        }
                    }

                    // Webmasterflag setzen
                    if($row['mem_usr_id'] > 0 && $row['rol_webmaster'] == 1)
                    {
                        $this->webmaster = 1;
                    }

                    // Listenansichtseinstellung merken
                    // Leiter duerfen die Rolle sehen
                    if($row['mem_usr_id'] > 0 && ($row['rol_this_list_view'] > 0 || $row['mem_leader'] == 1))
                    {
                        // Mitgliedschaft bei der Rolle und diese nicht gesperrt, dann anschauen
                        $this->list_view_rights[$row['rol_id']] = 1;
                    }
                    elseif($row['rol_this_list_view'] == 2)
                    {
                        // andere Rollen anschauen, wenn jeder sie sehen darf
                        $this->list_view_rights[$row['rol_id']] = 1;
                    }
                    else
                    {
                        $this->list_view_rights[$row['rol_id']] = 0;
                    }

                    // Mailrechte setzen
                    // Leiter duerfen der Rolle Mails schreiben
                    if($row['mem_usr_id'] > 0 && ($row['rol_mail_this_role'] > 0 || $row['mem_leader'] == 1))
                    {
                        // Mitgliedschaft bei der Rolle und diese nicht gesperrt, dann anschauen
                        $this->role_mail_rights[$row['rol_id']] = 1;
                    }
                    elseif($row['rol_mail_this_role'] >= 2)
                    {
                        // andere Rollen anschauen, wenn jeder sie sehen darf
                        $this->role_mail_rights[$row['rol_id']] = 1;
                    }
                    else
                    {
                        $this->role_mail_rights[$row['rol_id']] = 0;
                    }
                }
                $this->roles_rights = $tmp_roles_rights;

                // ist das Recht 'alle Listen einsehen' gesetzt, dann dies auch im Array bei allen Rollen setzen
                if($this->roles_rights['rol_all_lists_view'])
                {
                    foreach($this->list_view_rights as $key => $value)
                    {
                        $this->list_view_rights[$key] = 1;
                    }
                }

                // ist das Recht 'allen Rollen EMails schreiben' gesetzt, dann dies auch im Array bei allen Rollen setzen
                if($this->roles_rights['rol_mail_to_all'])
                {
                    foreach($this->role_mail_rights as $key => $value)
                    {
                        $this->role_mail_rights[$key] = 1;
                    }
                }

            }

            if(strlen($right) == 0 || $this->roles_rights[$right] == 1)
            {
                return true;
            }
        }
        return 0;
    }

    // alle Klassenvariablen wieder zuruecksetzen
    public function clear()
    {
        parent::clear();

        // die Daten der Profilfelder werden geloescht, die Struktur bleibt
		$this->mProfileFieldsData->clearUserData();

        $this->webmaster = 0;

        // Arrays initialisieren
        $this->roles_rights = array();
        $this->list_view_rights = array();
        $this->role_mail_rights = array();
    }
    
    // returns true if a column of user table or profile fields has changed
    public function columnsValueChanged()
    {
    	if($this->columnsValueChanged == true
    	|| $this->mProfileFieldsData->columnsValueChanged == true)
    	{
    		return true;
    	}
    
    	return false;
    }
    
    // delete all user data of profile fields; user record will not be deleted
    public function deleteUserFieldData()
    {
		$this->db->startTransaction();
		
        // delete every entry from adm_users_data
        foreach($this->mProfileFieldsData->mUserData as $field)
        {
			$field->delete();
        }
        
		$this->mProfileFieldsData->mUserData = array();
		$this->db->endTransaction();
    }
    
	/** Creates an array with all roles where the user has the right to view them
	 *  @return Array with roles where user has the right to view them
	 */
    public function getAllVisibleRoles()
    {
		$visibleRoles = array();
        $this->checkRolesRight();

		foreach($this->list_view_rights as $role => $right)
		{
			if($right == 1)
			{
				$visibleRoles[] = $role;
			}
		}
        return $visibleRoles;
    }
	
	// returns an array with all role ids where the user is a member
	public function getRoleMemberships()
	{
		$this->checkRolesRight();
		return $this->rolesMembership;
	}

    /** Get the value of a column of the database table if the column has the praefix @b usr_ 
     *  otherwise the value of the profile field of the table adm_user_data will be returned.
     *  If the value was manipulated before with @b setValue than the manipulated value is returned.
     *  @param $columnName The name of the database column whose value should be read or the internal unique profile field name
     *  @param $format For date or timestamp columns the format should be the date/time format e.g. @b d.m.Y = '02.04.2011'. @n
     *                 For text columns the format can be @b plain that would return the original database value without any transformations
     *  @return Returns the value of the database column or the value of adm_user_fields
     *          If the value was manipulated before with @b setValue than the manipulated value is returned.
	 *  @par Examples
	 *  @code  // reads data of adm_users column
	 *  $loginname = $gCurrentUser->getValue('usr_login_name');
	 *  // reads data of adm_user_fields
	 *  $email = $gCurrentUser->getValue('EMAIL'); @endcode
     */ 
    public function getValue($columnName, $format = '')
    {
        if(strpos($columnName, 'usr_') === 0)
        {
            return parent::getValue($columnName, $format);
        }
        else
        {
			return $this->mProfileFieldsData->getValue($columnName, $format);
        }
    }

	/** Creates a vcard with all data of this user object @n
	 *  (Windows XP address book can't process utf8, so vcard output is iso-8859-1)
	 *  @param $allowedToEditProfile If set to @b true than logged in user is allowed to edit profiles 
	 *                               so he can see more data in the vcard
	 *  @return Returns the vcard as a string
	 */
    public function getVCard($allowedToEditProfile = false)
    {
        global $gPreferences;

        $vcard  = (string) "BEGIN:VCARD\r\n";
        $vcard .= (string) "VERSION:2.1\r\n";
        if($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('FIRST_NAME', 'usf_hidden') == 0))
        {
            $vcard .= (string) "N;CHARSET=ISO-8859-1:" . utf8_decode($this->getValue('LAST_NAME')). ";". utf8_decode($this->getValue('FIRST_NAME')) . ";;;\r\n";
        }
        if($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('LAST_NAME', 'usf_hidden') == 0))
        {
            $vcard .= (string) "FN;CHARSET=ISO-8859-1:". utf8_decode($this->getValue('FIRST_NAME')) . " ". utf8_decode($this->getValue('LAST_NAME')) . "\r\n";
        }
        if (strlen($this->getValue('usr_login_name')) > 0)
        {
            $vcard .= (string) "NICKNAME;CHARSET=ISO-8859-1:" . utf8_decode($this->getValue("usr_login_name")). "\r\n";
        }
        if (strlen($this->getValue('PHONE')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('PHONE', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "TEL;HOME;VOICE:" . $this->getValue('PHONE'). "\r\n";
        }
        if (strlen($this->getValue('MOBILE')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('MOBILE', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "TEL;CELL;VOICE:" . $this->getValue('MOBILE'). "\r\n";
        }
        if (strlen($this->getValue('FAX')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('FAX', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "TEL;HOME;FAX:" . $this->getValue('FAX'). "\r\n";
        }
        if($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('ADDRESS', 'usf_hidden') == 0 && $this->mProfileFieldsData->getProperty('CITY', 'usf_hidden') == 0
        && $this->mProfileFieldsData->getProperty('POSTCODE', 'usf_hidden') == 0  && $this->mProfileFieldsData->getProperty('COUNTRY', 'usf_hidden') == 0))
        {
            $vcard .= (string) "ADR;CHARSET=ISO-8859-1;HOME:;;" . utf8_decode($this->getValue('ADDRESS')). ";" . utf8_decode($this->getValue('CITY')). ";;" . utf8_decode($this->getValue('POSTCODE')). ";" . utf8_decode($this->getValue('COUNTRY')). "\r\n";
        }
        if (strlen($this->getValue('WEBSITE')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('WEBSITE', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "URL;HOME:" . $this->getValue('WEBSITE'). "\r\n";
        }
        if (strlen($this->getValue('BIRTHDAY')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('BIRTHDAY', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "BDAY:" . $this->getValue('BIRTHDAY', 'Ymd') . "\r\n";
        }
        if (strlen($this->getValue('EMAIL')) > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('EMAIL', 'usf_hidden') == 0)))
        {
            $vcard .= (string) "EMAIL;PREF;INTERNET:" . $this->getValue('EMAIL'). "\r\n";
        }
        if (file_exists(SERVER_PATH.'/adm_my_files/user_profile_photos/'.$this->getValue('usr_id').'.jpg') && $gPreferences['profile_photo_storage'] == 1)
        {
            $img_handle = fopen (SERVER_PATH. '/adm_my_files/user_profile_photos/'.$this->getValue('usr_id').'.jpg', 'rb');
            $vcard .= (string) "PHOTO;ENCODING=BASE64;TYPE=JPEG:".base64_encode(fread ($img_handle, filesize (SERVER_PATH. '/adm_my_files/user_profile_photos/'.$this->getValue('usr_id').'.jpg'))). "\r\n";
            fclose($img_handle);
        }
        if (strlen($this->getValue('usr_photo')) > 0 && $gPreferences['profile_photo_storage'] == 0)
        {
            $vcard .= (string) "PHOTO;ENCODING=BASE64;TYPE=JPEG:".base64_encode($this->getValue('usr_photo')). "\r\n";
        }
        // Geschlecht ist nicht in vCard 2.1 enthalten, wird hier fuer das Windows-Adressbuch uebergeben
        if ($this->getValue('GENDER') > 0
        && ($allowedToEditProfile || ($allowedToEditProfile == false && $this->mProfileFieldsData->getProperty('GENDER', 'usf_hidden') == 0)))
        {
            if($this->getValue('GENDER') == 1)
            {
                $wab_gender = 2;
            }
            else
            {
                $wab_gender = 1;
            }
            $vcard .= (string) "X-WAB-GENDER:" . $wab_gender . "\r\n";
        }
        if (strlen($this->getValue('usr_timestamp_change')) > 0)
        {
            $vcard .= (string) "REV:" . $this->getValue('usr_timestamp_change', 'ymdThis') . "\r\n";
        }

        $vcard .= (string) "END:VCARD\r\n";
        return $vcard;
    }
	
	// check if user is leader of a role
	public function isLeaderOfRole($roleId)
	{
		if(array_key_exists($roleId, $this->rolesMembershipLeader))
		{
			return true;
		}
		else
		{
			return false;
		}
	}
	
	// check if user is member of a role
	public function isMemberOfRole($roleId)
	{
		if(in_array($roleId, $this->rolesMembership))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/** Reads a user record out of the table adm_users in database selected by the unique user id.
	 *  Also all profile fields of the object @b mProfileFieldsData will be read.
	 *  @param $userId Unique id of the user that should be read
	 *  @return Returns @b true if one record is found
	 */
    public function readDataById($userId)
    {
        if(parent::readDataById($userId))
        {
			// read data of all user fields from current user
			$this->mProfileFieldsData->readUserData($userId);
			return true;
		}

		return false;
    }
    
    // bei setValue werden die Werte nicht auf Gueltigkeit geprueft
    public function noValueCheck()
    {
        $this->mProfileFieldsData->noValueCheck();
    }

	/** Save all changed columns of the recordset in table of database. Therefore the class remembers if it's 
	 *  a new record or if only an update is neccessary. The update statement will only update
	 *  the changed columns. If the table has columns for creator or editor than these column
	 *  with their timestamp will be updated.
	 *  First save recordset and then save all user fields. After that the session of this got a renew for the user object.
	 *  @param $updateFingerPrint Default @b true. Will update the creator or editor of the recordset if table has columns like @b usr_id_create or @b usr_id_changed
	 */
    public function save($updateFingerPrint = true)
    {
        global $gCurrentSession;
        $fields_changed = $this->columnsValueChanged;
		$this->db->startTransaction();

		// if value of a field changed then update timestamp of user object
		if($this->mProfileFieldsData->columnsValueChanged)
		{
            $this->columnsValueChanged = true;
		}

        parent::save($updateFingerPrint);
		
		// save data of all user fields
		$this->mProfileFieldsData->saveUserData($this->getValue('usr_id'));

        if($fields_changed && is_object($gCurrentSession))
        {
			// now set user object in session of that user to invalid, 
			// because he has new data and maybe new rights
            $gCurrentSession->renewUserObject($this->getValue('usr_id'));
        }
		$this->db->endTransaction();
    }

	// edit an existing role membership for the current user
	// if additional memberships to this user and role exists within the period 
	// than merge them to the current membership
	public function editRoleMembership($memberId, $startDate = DATE_NOW, $endDate = '9999-12-31', $leader = '')
	{
		require_once('../../system/classes/table_members.php');
		$minStartDate = $startDate;
		$maxEndDate   = $endDate;
		
		$member = new TableMembers($this->db, $memberId);
		
		if(strlen($startDate) == 0 || strlen($startDate) == 0)
		{
			return false;
		}
		$this->db->startTransaction();
	
		// search for membership with same role and user and overlapping dates
		$sql = 'SELECT * FROM '.TBL_MEMBERS.' 
		         WHERE mem_id    <> '.$memberId.'
				   AND mem_rol_id = '.$member->getValue('mem_rol_id').'
				   AND mem_usr_id = '.$this->getValue('usr_id').'
				   AND mem_begin <= \''.$endDate.'\'
				   AND mem_end   >= \''.$startDate.'\'
				 ORDER BY mem_begin ASC ';
		$this->db->query($sql);

		if($this->db->num_rows() == 1)
		{
			// one record found than update this record
			$row = $this->db->fetch_array();
            $member->setArray($row);

			// save new start date if an earlier date exists
			if(strcmp($minStartDate, $member->getValue('mem_begin', 'Y-m-d')) > 0)
			{
				$minStartDate = $member->getValue('mem_begin', 'Y-m-d');
			}

			// save new end date if an later date exists
			if(strcmp($member->getValue('mem_end', 'Y-m-d'), $maxEndDate) > 0)
			{
				$maxEndDate = $member->getValue('mem_end', 'Y-m-d');
			}
		}
		elseif($this->db->num_rows() > 1)
		{
			// several records found then read min and max date and delete all records
			while($row = $this->db->fetch_array())
			{
				$member->clear();
				$member->setArray($row);

				// save new start date if an earlier date exists
				if(strcmp($minStartDate, $member->getValue('mem_begin', 'Y-m-d')) > 0)
				{
					$minStartDate = $member->getValue('mem_begin', 'Y-m-d');
				}

				// save new end date if an later date exists
				if(strcmp($member->getValue('mem_end', 'Y-m-d'), $maxEndDate) > 0)
				{
					$maxEndDate = $member->getValue('mem_end', 'Y-m-d');
				}
				
				// delete existing entry because a new overlapping entry will be created
				$member->delete();
			}
			$member->clear();
		}

		if(strcmp($minStartDate, $maxEndDate) > 0)
		{
			// if start date is greater than end date than delete membership
			if($member->getValue('mem_id') > 0)
			{
				$member->delete();
			}
		}
		else
		{
			// save membership to database
			$member->setValue('mem_begin', $minStartDate);
			$member->setValue('mem_end', $maxEndDate);
			if(strlen($leader) > 0)
			{
				$member->setValue('mem_leader', $leader);
			}
			$member->save();
		}

		$this->db->endTransaction();
		return true;
	}

	// set a role membership for the current user
	// if memberships to this user and role exists within the period than merge them to one new membership
	public function setRoleMembership($roleId, $startDate = DATE_NOW, $endDate = '9999-12-31', $leader = '')
	{
		require_once('../../system/classes/table_members.php');
		$minStartDate = $startDate;
		$maxEndDate   = $endDate;
		
		$member = new TableMembers($this->db);
		
		if(strlen($startDate) == 0 || strlen($startDate) == 0)
		{
			return false;
		}
		$this->db->startTransaction();
		
		// subtract 1 day from start date so that we find memberships that ends yesterday
		// these memberships can be continued with new date
		$dtStartDate = new DateTimeExtended($startDate, 'Y-m-d', 'date');
        $startDate = date('Y-m-d', $dtStartDate->getTimestamp() - (24 * 60 * 60));
        // add 1 to max date because we subtract one day if a membership ends
		$dtEndDate = new DateTimeExtended($endDate, 'Y-m-d', 'date');
        $endDate = date('Y-m-d', $dtStartDate->getTimestamp() + (24 * 60 * 60));
	
		// search for membership with same role and user and overlapping dates
		$sql = 'SELECT * FROM '.TBL_MEMBERS.' 
		         WHERE mem_rol_id = '.$roleId.'
				   AND mem_usr_id = '.$this->getValue('usr_id').'
				   AND mem_begin <= \''.$endDate.'\'
				   AND mem_end   >= \''.$startDate.'\'
				 ORDER BY mem_begin ASC ';
		$this->db->query($sql);

		if($this->db->num_rows() == 1)
		{
			// one record found than update this record
			$row = $this->db->fetch_array();
            $member->setArray($row);

			// save new start date if an earlier date exists
			if(strcmp($minStartDate, $member->getValue('mem_begin', 'Y-m-d')) > 0)
			{
				$minStartDate = $member->getValue('mem_begin', 'Y-m-d');
			}

			// save new end date if an later date exists
			// but only if end date is greater than the begin date otherwise the membership should be deleted
			if(strcmp($member->getValue('mem_end', 'Y-m-d'), $maxEndDate) > 0
			&& strcmp($member->getValue('mem_begin', 'Y-m-d'), $maxEndDate) < 0)
			{
				$maxEndDate = $member->getValue('mem_end', 'Y-m-d');
			}
		}
		elseif($this->db->num_rows() > 1)
		{
			// several records found then read min and max date and delete all records
			while($row = $this->db->fetch_array())
			{
				$member->clear();
				$member->setArray($row);

				// save new start date if an earlier date exists
				if(strcmp($minStartDate, $member->getValue('mem_begin', 'Y-m-d')) > 0)
				{
					$minStartDate = $member->getValue('mem_begin', 'Y-m-d');
				}

				// save new end date if an later date exists
				if(strcmp($member->getValue('mem_end', 'Y-m-d'), $maxEndDate) > 0)
				{
					$maxEndDate = $member->getValue('mem_end', 'Y-m-d');
				}
				
				// delete existing entry because a new overlapping entry will be created
				$member->delete();
			}
			$member->clear();
		}

		if(strcmp($minStartDate, $maxEndDate) > 0)
		{
			// if start date is greater than end date than delete membership
			if($member->getValue('mem_id') > 0)
			{
				$member->delete();
			}
		}
		else
		{
			// save membership to database
			$member->setValue('mem_rol_id', $roleId);
			$member->setValue('mem_usr_id', $this->getValue('usr_id'));
			$member->setValue('mem_begin', $minStartDate);
			$member->setValue('mem_end', $maxEndDate);
			if(strlen($leader) > 0)
			{
				$member->setValue('mem_leader', $leader);
			}
			$member->save();
		}

		$this->db->endTransaction();
		return true;
	}
	
    /** Set a new value for a column of the database table if the column has the praefix @b usr_ 
     *  otherwise the value of the profile field of the table adm_user_data will set.
     *  If the user log is activated than the change of the value will be logged in @b adm_user_log.
     *  The value is only saved in the object. You must call the method @b save to store the new value to the database
     *  @param $columnName The name of the database column whose value should get a new value or the internal unique profile field name
     *  @param $newValue The new value that should be stored in the database field
     *  @param $checkValue The value will be checked if it's valid. If set to @b false than the value will not be checked.  
     *  @return Returns @b true if the value is stored in the current object and @b false if a check failed
	 *  @par Examples
	 *  @code  // set data of adm_users column
	 *  $gCurrentUser->getValue('usr_login_name', 'Admidio');
	 *  // reads data of adm_user_fields
	 *  $gCurrentUser->getValue('EMAIL', 'webmaster@admidio.org'); @endcode
     */ 
    public function setValue($columnName, $newValue, $checkValue = true)
    {
        global $gCurrentUser, $gPreferences;

        $returnCode    = true;
        $updateField   = false;
        $oldFieldValue = $this->mProfileFieldsData->getValue($columnName);

        if(strpos($columnName, 'usr_') !== 0)
        {
            // Daten fuer User-Fields-Tabelle

            // gesperrte Felder duerfen nur von Usern mit dem Rollenrecht 'alle Benutzerdaten bearbeiten' geaendert werden
            // bei Registrierung muss die Eingabe auch erlaubt sein
            if((  $this->mProfileFieldsData->getProperty($columnName, 'usf_disabled') == 1
               && $gCurrentUser->editUsers() == true)
            || $this->mProfileFieldsData->getProperty($columnName, 'usf_disabled') == 0
            || ($gCurrentUser->getValue('usr_id') == 0 && $this->getValue('usr_id') == 0))
            {
                // versteckte Felder duerfen nur von Usern mit dem Rollenrecht 'alle Benutzerdaten bearbeiten' geaendert werden
                // oder im eigenen Profil
                if((  $this->mProfileFieldsData->getProperty($columnName, 'usf_hidden') == 1
                   && $gCurrentUser->editUsers() == true)
                || $this->mProfileFieldsData->getProperty($columnName, 'usf_hidden') == 0
                || $gCurrentUser->getValue('usr_id') == $this->getValue('usr_id'))
                {
                    $updateField = true;
                }
            }

            // nur Updaten, wenn sich auch der Wert geaendert hat
            if($updateField == true
            && $newValue  != $oldFieldValue)
            {
				$returnCode = $this->mProfileFieldsData->setValue($columnName, $newValue);
            }
        }
        else
        {
            $returnCode = parent::setValue($columnName, $newValue);
        }

        $newFieldValue = $this->mProfileFieldsData->getValue($columnName);
		
        /*  Nicht alle Aenderungen werden geloggt. Ausnahmen:
         *  usr_id ist Null, wenn der User neu angelegt wird. Das wird bereits dokumentiert.
         *  Felder, die mit usr_ beginnen, werden nicht geloggt
         *  Falls die Feldwerte sich nicht geaendert haben, wird natuerlich ebenfalls nicht geloggt 
         */
        if($gPreferences['profile_log_edit_fields'] == 1
		&& $this->getValue('usr_id') != 0 
		&& strpos($columnName,'usr_') === false 
		&& $newFieldValue != $oldFieldValue)
        {
			$logEntry = new TableAccess($this->db, TBL_USER_LOG, 'usl');
			$logEntry->setValue('usl_usr_id', $this->getValue('usr_id'));
			$logEntry->setValue('usl_usf_id', $this->mProfileFieldsData->getProperty($columnName, 'usf_id'));
			$logEntry->setValue('usl_value_old', $oldFieldValue);
			$logEntry->setValue('usl_value_new', $newFieldValue);
			$logEntry->setValue('usl_comm', '');
			$logEntry->save();
        }
        return $returnCode;
    }

    // Funktion prueft, ob der angemeldete User Ankuendigungen anlegen und bearbeiten darf
    public function editAnnouncements()
    {
        return $this->checkRolesRight('rol_announcements');
    }

    // Funktion prueft, ob der angemeldete User Registrierungen bearbeiten und zuordnen darf
    public function approveUsers()
    {
        return $this->checkRolesRight('rol_approve_users');
    }

    // Funktion prueft, ob der angemeldete User Rollen zuordnen, anlegen und bearbeiten darf
    public function assignRoles()
    {
        return $this->checkRolesRight('rol_assign_roles');
    }

    //Ueberprueft ob der User das Recht besitzt, alle Rollenlisten einsehen zu duerfen
    public function viewAllLists()
    {
        return $this->checkRolesRight('rol_all_lists_view');
    }

    //Ueberprueft ob der User das Recht besitzt, allen Rollenmails zu zusenden
    public function mailAllRoles()
    {
        return $this->checkRolesRight('rol_mail_to_all');
    }

    // Funktion prueft, ob der angemeldete User Termine anlegen und bearbeiten darf
    public function editDates()
    {
        return $this->checkRolesRight('rol_dates');
    }

    // Funktion prueft, ob der angemeldete User Downloads hochladen und verwalten darf
    public function editDownloadRight()
    {
        return $this->checkRolesRight('rol_download');
    }

	// checks if the current user is allowed to edit the profile of the user
	public function editProfile($user)
	{
		// edit own profile ?
		if($user->getValue('usr_id') == $this->getValue('usr_id') 
		&& $this->getValue('usr_id') > 0)
		{
            $edit_profile = $this->checkRolesRight('rol_profile');

            if($edit_profile == 1)
            {
                return true;
            }
		}
		
		if($this->editUsers())
		{
			return true;
		}
		else
		{
			if(count($this->rolesMembershipLeader) > 0)
			{
				// check if current user is a group leader of a role where $user is a member
				$rolesMembership = $user->getRoleMemberships();
				foreach($this->rolesMembershipLeader as $roleId => $leaderRights)
				{
					// is group leader of role and has the right to edit users ?
					if(array_search($roleId, $rolesMembership) != false
					&& $leaderRights > 1)
					{
						return true;
					}
				}
			}
		}

		return false;
	}

    // Funktion prueft, ob der angemeldete User fremde Benutzerdaten bearbeiten darf
    public function editUsers()
    {
        return $this->checkRolesRight('rol_edit_user');
    }

    // Funktion prueft, ob der angemeldete User Gaestebucheintraege loeschen und editieren darf
    public function editGuestbookRight()
    {
        return $this->checkRolesRight('rol_guestbook');
    }

    // Funktion prueft, ob der angemeldete User Gaestebucheintraege kommentieren darf
    public function commentGuestbookRight()
    {
        return $this->checkRolesRight('rol_guestbook_comments');
    }

    // Funktion prueft, ob der angemeldete User Fotos hochladen und verwalten darf
    public function editPhotoRight()
    {
        return $this->checkRolesRight('rol_photo');
    }

    // Funktion prueft, ob der angemeldete User Weblinks anlegen und editieren darf
    public function editWeblinksRight()
    {
        return $this->checkRolesRight('rol_weblinks');
    }

    // Funktion prueft, ob der User ein Profil einsehen darf
	// check if current user object is allowed to read user of parameter
    public function viewProfile($user)
    {
        global $gCurrentOrganization;
        $view_profile = false;

        //Hat ein User Profileedit rechte, darf er es natuerlich auch sehen
        if($this->editProfile($user))
        {
            $view_profile = true;
        }
        else
        {
            // Benutzer, die alle Listen einsehen duerfen, koennen auch alle Profile sehen
            if($this->viewAllLists())
            {
                $view_profile = true;
            }
            else
            {
                $sql    = 'SELECT rol_id, rol_this_list_view
                             FROM '. TBL_MEMBERS. ', '. TBL_ROLES. ', '. TBL_CATEGORIES. '
                            WHERE mem_usr_id = '.$user->getValue('usr_id'). '
                              AND mem_begin <= \''.DATE_NOW.'\'
                              AND mem_end    > \''.DATE_NOW.'\'
                              AND mem_rol_id = rol_id
                              AND rol_valid  = 1
                              AND rol_cat_id = cat_id
                              AND (  cat_org_id = '. $gCurrentOrganization->getValue('org_id').'
                                  OR cat_org_id IS NULL ) ';
                $this->db->query($sql);

                if($this->db->num_rows() > 0)
                {
                    while($row = $this->db->fetch_array())
                    {
                        if($row['rol_this_list_view'] == 2)
                        {
                            // alle angemeldeten Benutzer duerfen Rollenlisten/-profile sehen
                            $view_profile = true;
                        }
                        elseif($row['rol_this_list_view'] == 1
                        && isset($this->list_view_rights[$row['rol_id']]))
                        {
                            // nur Rollenmitglieder duerfen Rollenlisten/-profile sehen
                            $view_profile = true;
                        }
                    }
                }
            }
        }
        return $view_profile;
    }

    // Methode prueft, ob der angemeldete User eine bestimmte oder alle Listen einsehen darf
    public function viewRole($rol_id)
    {
        $view_role = false;
        // Abfrage ob der User durch irgendeine Rolle das Recht bekommt alle Listen einzusehen
        if($this->viewAllLists())
        {
            $view_role = true;
        }
        else
        {
            // Falls er das Recht nicht hat Kontrolle ob fuer eine bestimmte Rolle
            if(isset($this->list_view_rights[$rol_id]) && $this->list_view_rights[$rol_id] > 0)
            {
                $view_role = true;
            }
        }
        return $view_role;
    }

	// Methode prueft, ob der angemeldete User einer bestimmten oder allen Rolle E-Mails zusenden darf
    public function mailRole($rol_id)
    {
        $mail_role = false;
        // Abfrage ob der User durch irgendeine Rolle das Recht bekommt alle Listen einzusehen
        if($this->mailAllRoles())
        {
            $mail_role = true;
        }
        else
        {
            // Falls er das Recht nicht hat Kontrolle ob fuer eine bestimmte Rolle
            if(isset($this->role_mail_rights[$rol_id]) && $this->role_mail_rights[$rol_id] > 0)
            {
                $mail_role = true;
            }
        }
        return $mail_role;
    }

    /** Checks if the user is assigned to the role @b Webmaster
     *  @return Returns @b true if the user is a member of the role @b Webmaster
     */
    public function isWebmaster()
    {
        $this->checkRolesRight();
        return $this->webmaster;
    }
}
?>