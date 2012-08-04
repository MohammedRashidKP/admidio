<?php
/******************************************************************************
 * Class manages access to database table adm_links
 *
 * Copyright    : (c) 2004 - 2012 The Admidio Team
 * Homepage     : http://www.admidio.org
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This class creates objects of the database table links. 
 * You can read, change and create weblinks in the database.
 *
 *****************************************************************************/

require_once(SERVER_PATH. '/adm_program/system/classes/table_access.php');

class TableWeblink extends TableAccess
{
	/** Constuctor that will create an object of a recordset of the table adm_links. 
	 *  If the id is set than the specific weblink will be loaded.
	 *  @param $db Object of the class database. This should be the default object $gDb.
	 *  @param $lnk_id The recordset of the weblink with this id will be loaded. If id isn't set than an empty object of the table is created.
	 */
    public function __construct(&$db, $lnk_id = 0)
    {
		// read also data of assigned category
		$this->connectAdditionalTable(TBL_CATEGORIES, 'cat_id', 'lnk_cat_id');

		parent::__construct($db, TBL_LINKS, 'lnk', $lnk_id);
    }

	// returns the value of database column $field_name
	// for column usf_value_list the following format is accepted
	// 'plain' -> returns database value of usf_value_list
    public function getValue($field_name, $format = '')
    {
		global $gL10n;

        if($field_name == 'lnk_description')
        {
			if(isset($this->dbColumns['lnk_description']) == false)
			{
				$value = '';
			}
			elseif($format == 'plain')
			{
				$value = html_entity_decode(strStripTags($this->dbColumns['lnk_description']));
			}
			else
			{
				$value = $this->dbColumns['lnk_description'];
			}
        }
        else
        {
            $value = parent::getValue($field_name, $format);
        }

		if($field_name == 'cat_name' && $format != 'plain')
		{
			// if text is a translation-id then translate it
			if(strpos($value, '_') == 3)
			{
				$value = $gL10n->get(admStrToUpper($value));
			}
		}

        return $value;
    }
    
    // validates the value and adapts it if necessary
    public function setValue($field_name, $field_value, $check_value = true)
    {
        if($field_name == 'lnk_url' && strlen($field_value) > 0)
        {
			// Homepage darf nur gueltige Zeichen enthalten
			if (!strValidCharacters($field_value, 'url'))
			{
				return false;
			}
			// Homepage noch mit http vorbelegen
			if(strpos(admStrToLower($field_value), 'http://')  === false
			&& strpos(admStrToLower($field_value), 'https://') === false )
			{
				$field_value = 'http://'. $field_value;
			}
        }
        elseif($field_name == 'lnk_description')
        {
            return parent::setValue($field_name, $field_value, false);
        }
        return parent::setValue($field_name, $field_value);
    } 
}
?>