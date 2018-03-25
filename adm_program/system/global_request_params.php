<?php
declare(strict_types=1);
/**
 ***********************************************************************************************
 * Remove HTML & PHP-Code and escape all quotes from all request parameters.
 * If debug is on and change is made, log it.
 *
 * @copyright 2004-2018 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
if (basename($_SERVER['SCRIPT_FILENAME']) === 'global_request_params.php')
{
    exit('This page may not be called directly!');
}

$getOrig    = array();
$postOrig   = array();
$cookieOrig = array();
if ($gDebug)
{
    $getOrig    = $_GET;
    $postOrig   = $_POST;
    $cookieOrig = $_COOKIE;
}

// remove HTML & PHP-Code from all parameters
$_GET    = admStrStripTagsSpecial($_GET);
$_POST   = admStrStripTagsSpecial($_POST);
$_COOKIE = admStrStripTagsSpecial($_COOKIE);

// escape all quotes so db queries are save
// TODO deprecated: Remove in Admidio 4.0
if(!get_magic_quotes_gpc())
{
    $_GET    = strAddSlashesDeep($_GET);
    $_POST   = strAddSlashesDeep($_POST);
    $_COOKIE = strAddSlashesDeep($_COOKIE);
}

if ($gDebug)
{
    $diff = array('GET' => array(), 'POST' => array(), 'COOKIE' => array());

    foreach ($getOrig as $getOrigKey => $getOrigVal)
    {
        if ($_GET[$getOrigKey] !== $getOrigVal)
        {
            $diff['GET'][$getOrigKey] = array(
                'original' => $getOrigVal,
                'changed'  => $_GET[$getOrigKey]
            );
        }
    }
    foreach ($postOrig as $postOrigKey => $postOrigVal)
    {
        if ($_POST[$postOrigKey] !== $postOrigVal)
        {
            $diff['POST'][$postOrigKey] = array(
                'original' => $postOrigVal,
                'changed'  => $_POST[$postOrigKey]
            );
        }
    }
    foreach ($cookieOrig as $cookieOrigKey => $cookieOrigVal)
    {
        if ($_COOKIE[$cookieOrigKey] !== $cookieOrigVal)
        {
            $diff['COOKIE'][$cookieOrigKey] = array(
                'original' => $cookieOrigVal,
                'changed'  => $_COOKIE[$cookieOrigKey]
            );
        }
    }

    if (count($diff['GET']) > 0 || count($diff['POST']) > 0 || count($diff['COOKIE']) > 0)
    {
        $gLogger->warning('Dangerous parameters requested!', $diff);
    }
}
