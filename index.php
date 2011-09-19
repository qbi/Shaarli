<?php
// Shaarli 0.0.12 beta - Shaare your links...
// The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net
// http://sebsauvage.net/wiki/doku.php?id=php:shaarli
// Licence: http://www.opensource.org/licenses/zlib-license.php

// Requires: php 5.1.x

// -----------------------------------------------------------------------------------------------
// User config:
define('DATADIR','data'); // Data subdirectory
define('CONFIG_FILE',DATADIR.'/config.php'); // Configuration file (user login/password)
define('DATASTORE',DATADIR.'/datastore.php'); // Data storage file.
define('LINKS_PER_PAGE',20); // Default links per page.
define('IPBANS_FILENAME',DATADIR.'/ipbans.php'); // File storage for failures and bans.
define('BAN_AFTER',4);       // Ban IP after this many failures.
define('BAN_DURATION',1800); // Ban duration for IP address after login failures (in seconds) (1800 sec. = 30 minutes)
define('OPEN_SHAARLI',false); // If true, anyone can add/edit/delete links without having to login
if (get_magic_quotes_gpc())
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: magic_quotes_gpc is ON in your php config. This is *BAD*. You *MUST* disable it, either by changing the value in php.ini,\n";
    echo "or by adding the following line in .htaccess: php_flag magic_quotes_gpc Off"; exit;
}
checkphpversion();

// -----------------------------------------------------------------------------------------------
// Program config (touch at your own risks !)
error_reporting(E_ALL^E_WARNING);  // See all error except warnings.
//error_reporting(-1); // See all errors (for debugging only)
$STARTTIME = microtime(true);  // Measure page execution time.
ob_start();
// Prevent caching: (yes, it's ugly)
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
define('shaarli_version','0.0.12 beta');
if (!is_dir(DATADIR)) { mkdir(DATADIR,0705); chmod(DATADIR,0705); }
if (!is_file(DATADIR.'/.htaccess')) { file_put_contents(DATADIR.'/.htaccess',"Allow from none\nDeny from all\n"); } // Protect data files.    
if (!is_file(CONFIG_FILE)) install();
require CONFIG_FILE;  // Read login/password hash into $GLOBALS.
ini_set('max_input_time','60');  // High execution time in case of problematic imports/exports.
ini_set('memory_limit', '128M');  // Try to set max upload file size and read (May not work on some hosts).
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');
define('PHPPREFIX','<?php /* '); // Prefix to encapsulate data in php code.
define('PHPSUFFIX',' */ ?>'); // Suffix to encapsulate data in php code.
autoLocale(); // Sniff browser language and set date format accordingly.
header('Content-Type: text/html; charset=utf-8'); // We use UTF-8 for proper international characters handling.
$LINKSDB=false;

// Check php version
function checkphpversion()
{
    $ver=phpversion();
    if (preg_match('!(\d+)\.(\d+)\.(\d+)!',$ver,$matches)) // (because phpversion() sometimes returns strings like "5.2.4-2ubuntu5.2")
    {
        list($match,$major,$minor,$release) = $matches;
        if ($major>=5 && $minor>=1) return; // 5.1.x or higher is ok.
        die('Your server supports php '.$ver.'. Shaarli requires at last php 5.1, and thus cannot run. Sorry.');
    }
    // if cannot check php version... well, at your own risks.
}

// -----------------------------------------------------------------------------------------------
// Log to text file
function logm($message)
{
    if (!file_exists(DATADIR.'/log.txt')) {$logFile = fopen(DATADIR.'/log.txt','w'); }
    else { $logFile = fopen(DATADIR.'/log.txt','a'); }
    fwrite($logFile,strval(date('Y/m/d_H:i:s')).' - '.$_SERVER["REMOTE_ADDR"].' - '.strval($message)."\n");
    fclose($logFile);
}

// ------------------------------------------------------------------------------------------
// Sniff browser language to display dates in the right format automatically.
// (Note that is may not work on your server if the corresponding local is not installed.)
function autoLocale()
{     
    $loc='en_US'; // Default if browser does not send HTTP_ACCEPT_LANGUAGE
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) // eg. "fr,fr-fr;q=0.8,en;q=0.5,en-us;q=0.3"
    {   // (It's a bit crude, but it works very well. Prefered language is always presented first.)
        if (preg_match('/([a-z]{2}(-[a-z]{2})?)/i',$_SERVER['HTTP_ACCEPT_LANGUAGE'],$matches)) $loc=$matches[1];
    }
    setlocale(LC_TIME,$loc);  // LC_TIME = Set local for date/time format only.
}

// ------------------------------------------------------------------------------------------
// Session management
define('INACTIVITY_TIMEOUT',3600); // (in seconds). If the user does not access any page within this time, his/her session is considered expired.
ini_set('session.use_cookies', 1);       // Use cookies to store session.
ini_set('session.use_only_cookies', 1);  // Force cookies for session (phpsessionID forbidden in URL)
ini_set('session.use_trans_sid', false); // Prevent php to use sessionID in URL if cookies are disabled.
session_name('shaarli');
session_start();

// Returns the IP address of the client (Used to prevent session cookie hijacking.)
function allIPs()
{
    $ip = $_SERVER["REMOTE_ADDR"];
    // Then we use more HTTP headers to prevent session hijacking from users behind the same proxy.
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip=$ip.'_'.$_SERVER['HTTP_X_FORWARDED_FOR']; }
    if (isset($_SERVER['HTTP_CLIENT_IP'])) { $ip=$ip.'_'.$_SERVER['HTTP_CLIENT_IP']; }
    return $ip;
}

// Check that user/password is correct.
function check_auth($login,$password) 
{
    $hash = sha1($password.$login.$GLOBALS['salt']);
    if ($login==$GLOBALS['login'] && $hash==$GLOBALS['hash'])
    {   // Login/password is correct.
        $_SESSION['uid'] = sha1(uniqid('',true).'_'.mt_rand()); // generate unique random number (different than phpsessionid)
        $_SESSION['ip']=allIPs();                // We store IP address(es) of the client to make sure session is not hijacked.
        $_SESSION['username']=$login;
        $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT;  // Set session expiration.
        logm('Login successful');
        return True;
    }
    logm('Login failed for user '.$login);
    return False;
}

// Returns true if the user is logged in.
function isLoggedIn()
{ 
    if (OPEN_SHAARLI) return true; 
    
    // If session does not exist on server side, or IP address has changed, or session has expired, logout.
    if (empty($_SESSION['uid']) || $_SESSION['ip']!=allIPs() || time()>=$_SESSION['expires_on'])
    {
        logout();
        return false;
    }
    $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT;  // User accessed a page : Update his/her session expiration date.
    return true;
}

// Force logout.
function logout() { unset($_SESSION['uid']); unset($_SESSION['ip']); unset($_SESSION['username']);}


// ------------------------------------------------------------------------------------------
// Brute force protection system
// Several consecutive failed logins will ban the IP address for 30 minutes.
if (!is_file(IPBANS_FILENAME)) file_put_contents(IPBANS_FILENAME, "<?php\n\$GLOBALS['IPBANS']=".var_export(array('FAILURES'=>array(),'BANS'=>array()),true).";\n?>");
include IPBANS_FILENAME;
// Signal a failed login. Will ban the IP if too many failures:
function ban_loginFailed()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (!isset($gb['FAILURES'][$ip])) $gb['FAILURES'][$ip]=0;
    $gb['FAILURES'][$ip]++;
    if ($gb['FAILURES'][$ip]>(BAN_AFTER-1))
    {
        $gb['BANS'][$ip]=time()+BAN_DURATION;
        logm('IP address banned from login');
    }
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents(IPBANS_FILENAME, "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Signals a successful login. Resets failed login counter.
function ban_loginOk()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents(IPBANS_FILENAME, "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Checks if the user CAN login. If 'true', the user can try to login.
function ban_canLogin()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (isset($gb['BANS'][$ip]))
    {
        // User is banned. Check if the ban has expired:
        if ($gb['BANS'][$ip]<=time())  
        {   // Ban expired, user can try to login again.
            logm('Ban lifted.');
            unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
            file_put_contents(IPBANS_FILENAME, "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
            return true; // Ban has expired, user can login.
        }
        return false; // User is banned.
    }
    return true; // User is not banned.
}

// ------------------------------------------------------------------------------------------
// Process login form: Check if login/password is correct.
if (isset($_POST['login']))
{
    if (!ban_canLogin()) die('I said: NO. You are banned for the moment. Go away.');
    if (isset($_POST['password']) && tokenOk($_POST['token']) && (check_auth($_POST['login'], $_POST['password'])))
    {   // Login/password is ok.
        ban_loginOk();
        // Optional redirect after login:
        if (isset($_GET['post'])) { header('Location: ?post='.urlencode($_GET['post']).(!empty($_GET['source'])?'&source='.urlencode($_GET['source']):'')); exit; }
        if (isset($_POST['returnurl'])) { header('Location: '.$_POST['returnurl']); exit; }
        header('Location: ?'); exit;
    }
    else
    {
        ban_loginFailed(); 
        echo '<script language="JavaScript">alert("Wrong login/password.");document.location=\'?do=login\';</script>'; // Redirect to login screen.
        exit;   
    }
} 

// ------------------------------------------------------------------------------------------
// Misc utility functions:

// Returns the server URL (including port and http/https), without path.
// eg. "http://myserver.com:8080"
// You can append $_SERVER['SCRIPT_NAME'] to get the current script URL.
function serverUrl()
{
        $serverport = ($_SERVER["SERVER_PORT"]!='80' ? ':'.$_SERVER["SERVER_PORT"] : ''); 
        return 'http'.(!empty($_SERVER['HTTPS'])?'s':'').'://'.$_SERVER["SERVER_NAME"].$serverport;
}

// Convert post_max_size/upload_max_filesize (eg.'16M') parameters to bytes.
function return_bytes($val) 
{
    $val = trim($val); $last=strtolower($val[strlen($val)-1]);
    switch($last) 
    {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// Try to determine max file size for uploads (POST).
// Returns an integer (in bytes)
function getMaxFileSize()
{
    $size1 = return_bytes(ini_get('post_max_size'));
    $size2 = return_bytes(ini_get('upload_max_filesize'));
    // Return the smaller of two:
    $maxsize = min($size1,$size2);
    // FIXME: Then convert back to readable notations ? (eg. 2M instead of 2000000)
    return $maxsize;
}

// Tells if a string start with a substring or not.
function startsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, 0, strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, 0, strlen($needle)),$needle)===0);
}

// Tells if a string ends with a substring or not.
function endsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a timestamp (Unix epoch)
    (used to build the ADD_DATE attribute in Netscape-bookmarks file)
    PS: I could have used strptime(), but it does not exist on Windows. I'm too kind. */
function linkdate2timestamp($linkdate)
{
    $Y=$M=$D=$h=$m=$s=0;
    $r = sscanf($linkdate,'%4d%2d%2d_%2d%2d%2d',$Y,$M,$D,$h,$m,$s);
    return mktime($h,$m,$s,$M,$D,$Y);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a RFC822 date.
    (used to build the pubDate attribute in RSS feed.)  */
function linkdate2rfc822($linkdate)
{
    return date('r',linkdate2timestamp($linkdate)); // 'r' is for RFC822 date format.
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a localized date format.
    (used to display link date on screen)
    The date format is automatically chose according to locale/languages sniffed from browser headers (see autoLocale()). */
function linkdate2locale($linkdate)
{
    return utf8_encode(strftime('%c',linkdate2timestamp($linkdate))); // %c is for automatic date format according to locale.
    // Note that if you use a local which is not installed on your webserver,
    // the date will not be displayed in the chosen locale, but probably in US notation.
}

// Parse HTTP response headers and return an associative array.
function http_parse_headers( $headers ) 
{
    $res=array();
    foreach($headers as $header)
    {
        $i = strpos($header,': ');
        if ($i)
        {
            $key=substr($header,0,$i);
            $value=substr($header,$i+2,strlen($header)-$i-2);
            $res[$key]=$value;
        }
    }
    return $res;
}

/* GET an URL.
   Input: $url : url to get (http://...)
          $timeout : Network timeout (will wait this many seconds for an anwser before giving up).
   Output: An array.  [0] = HTTP status message (eg. "HTTP/1.1 200 OK") or error message
                      [1] = associative array containing HTTP response headers (eg. echo getHTTP($url)[1]['Content-Type'])
                      [2] = data
    Example: list($httpstatus,$headers,$data) = getHTTP('http://sebauvage.net/');
             if (strpos($httpstatus,'200 OK'))
                 echo 'Data type: '.htmlspecialchars($headers['Content-Type']);
             else
                 echo 'There was an error: '.htmlspecialchars($httpstatus)
*/
function getHTTP($url,$timeout=30)
{
    try
    {
        $options = array('http'=>array('method'=>'GET','timeout' => $timeout)); // Force network timeout
        $context = stream_context_create($options);
        $data=file_get_contents($url,false,$context,-1, 2000000); // We download at most 2 Mb from source.
        if (!$data) { $lasterror=error_get_last();  return array($lasterror['message'],array(),''); }
        $httpStatus=$http_response_header[0]; // eg. "HTTP/1.1 200 OK"
        $responseHeaders=http_parse_headers($http_response_header);
        return array($httpStatus,$responseHeaders,$data);
    }
    catch (Exception $e)  // getHTTP *can* fail silentely (we don't care if the title cannot be fetched)
    {
        return array($e->getMessage(),'','');
    }
}

// Extract title from an HTML document.
// (Returns an empty string if not found.)
function html_extract_title($html) 
{
  return preg_match('!<title>(.*?)</title>!i', $html, $matches) ? $matches[1] : '';
}

// ------------------------------------------------------------------------------------------
// Token management for XSRF protection
// Token should be used in any form which acts on data (create,update,delete,import...).
if (!isset($_SESSION['tokens'])) $_SESSION['tokens']=array();  // Token are attached to the session.

// Returns a token.
function getToken()
{
    $rnd = sha1(uniqid('',true).'_'.mt_rand());  // We generate a random string.
    $_SESSION['tokens'][$rnd]=1;  // Store it on the server side.
    return $rnd;    
}

// Tells if a token is ok. Using this function will destroy the token.
// true=token is ok. 
function tokenOk($token)
{
    if (isset($_SESSION['tokens'][$token]))
    {
        unset($_SESSION['tokens'][$token]); // Token is used: destroy it.
        return true; // Token is ok.
    }
    return false; // Wrong token, or already used.
}

// ------------------------------------------------------------------------------------------
/* Data storage for links.
   This object behaves like an associative array.
   Example:
      $mylinks = new linkdb();
      echo $mylinks['20110826_161819']['title'];
      foreach($mylinks as $link)
         echo $link['title'].' at url '.$link['url'].' ; description:'.$link['description'];
   
   We implement 3 interfaces:
     - ArrayAccess so that this object behaves like an associative array.
     - Iterator so that this object can be used in foreach() loops.
     - Countable interface so that we can do a count() on this object.
*/
class linkdb implements Iterator, Countable, ArrayAccess

{
    private $links; // List of links (associative array. Key=linkdate (eg. "20110823_124546"), value= associative array (keys:title,description...)
    private $urls;  // List of all recorded URLs (key=url, value=linkdate) for fast reserve search (url-->linkdate)
    private $keys;  // List of linkdate keys (for the Iterator interface implementation)
    private $position; // Position in the $this->keys array. (for the Iterator interface implementation.)
    private $loggedin; // Is the used logged in ? (used to filter private links)

    // Constructor:
    function __construct($isLoggedIn)
    // Input : $isLoggedIn : is the used logged in ?
    {
        $this->loggedin = $isLoggedIn;
        $this->checkdb(); // Make sure data file exists.
        $this->readdb();  // Then read it.
    } 
    
    // ---- Countable interface implementation
    public function count() { return count($this->links); }

    // ---- ArrayAccess interface implementation
    public function offsetSet($offset, $value)
    {
        if (!$this->loggedin) die('You are not authorized to add a link.');
        if (empty($value['linkdate']) || empty($value['url'])) die('Internal Error: A link should always have a linkdate and url.');
        if (empty($offset)) die('You must specify a key.');
        $this->links[$offset] = $value;
        $this->urls[$value['url']]=$offset;    
    }
    public function offsetExists($offset) { return array_key_exists($offset,$this->links); }
    public function offsetUnset($offset)
    { 
        if (!$this->loggedin) die('You are not authorized to delete a link.');
        $url = $this->links[$offset]['url']; unset($this->urls[$url]);    
        unset($this->links[$offset]); 
    }
    public function offsetGet($offset) { return isset($this->links[$offset]) ? $this->links[$offset] : null; }
    
    // ---- Iterator interface implementation
    function rewind() { $this->keys=array_keys($this->links); rsort($this->keys); $this->position=0; } // Start over for iteration, ordered by date (latest first).
    function key() { return $this->keys[$this->position]; } // current key
    function current() { return $this->links[$this->keys[$this->position]]; } // current value
    function next() { ++$this->position; } // go to next item
    function valid() { return isset($this->keys[$this->position]); }    // Check if current position is valid.

    // ---- Misc methods
    private function checkdb() // Check if db directory and file exists.
    {
        if (!file_exists(DATASTORE)) // Create a dummy database for example.
        {
             $this->links = array();
             $link = array('title'=>'Shaarli - sebsauvage.net','url'=>'http://sebsauvage.net/wiki/doku.php?id=php:shaarli','description'=>'Welcome to Shaarli ! This is a bookmark. To edit or delete me, you must first login.','private'=>0,'linkdate'=>'20110914_190000','tags'=>'opensource software');
             $this->links[$link['linkdate']] = $link;
             $link = array('title'=>'My secret stuff... - Pastebin.com','url'=>'http://pastebin.com/smCEEeSn','description'=>'SShhhh!!  I\'m a private link only YOU can see. You can delete me too.','private'=>1,'linkdate'=>'20110914_074522','tags'=>'secretstuff');
             $this->links[$link['linkdate']] = $link;             
             file_put_contents(DATASTORE, PHPPREFIX.base64_encode(gzdeflate(serialize($this->links))).PHPSUFFIX); // Write database to disk
        }    
    }
    
    // Read database from disk to memory
    private function readdb() 
    {
        // Read data
        $this->links=(file_exists(DATASTORE) ? unserialize(gzinflate(base64_decode(substr(file_get_contents(DATASTORE),strlen(PHPPREFIX),-strlen(PHPSUFFIX))))) : array() );
        
        // If user is not logged in, filter private links.
        if (!$this->loggedin)
        {
            $toremove=array();
            foreach($this->links as $link) { if ($link['private']!=0) $toremove[]=$link['linkdate']; }
            foreach($toremove as $linkdate) { unset($this->links[$linkdate]); }
        }
        
        // Keep the list of the mapping URLs-->linkdate up-to-date.
        $this->urls=array();
        foreach($this->links as $link) { $this->urls[$link['url']]=$link['linkdate']; }
    }

    // Save database from memory to disk.    
    public function savedb() 
    {
        if (!$this->loggedin) die('You are not authorized to change the database.');
        file_put_contents(DATASTORE, PHPPREFIX.base64_encode(gzdeflate(serialize($this->links))).PHPSUFFIX);
    }
    
    // Returns the link for a given URL (if it exists). false it does not exist.
    public function getLinkFromUrl($url) 
    {
        if (isset($this->urls[$url])) return $this->links[$this->urls[$url]];
        return false;
    }

    // Case insentitive search among links (in url, title and description). Returns filtered list of links.
    // eg. print_r($mydb->filterTags('hollandais'));
    public function filterFulltext($searchterms)  
    {
        // FIXME: explode(' ',$searchterms) and perform a AND search.
        // FIXME: accept double-quotes to search for a string "as is" ?
        $filtered=array();
        $s = strtolower($searchterms);
        foreach($this->links as $l)
        { 
            $found=strpos(strtolower($l['title']),$s) || strpos(strtolower($l['description']),$s) || strpos(strtolower($l['url']),$s) || strpos(strtolower($l['tags']),$s);
            if ($found) $filtered[$l['linkdate']] = $l;
        }
        krsort($filtered);
        return $filtered;
    }
    
    // Filter by tag.
    // You can specify one or more tags (tags can be separated by space or comma).
    // eg. print_r($mydb->filterTags('linux programming'));
    public function filterTags($tags)
    {
        $t = str_replace(',',' ',strtolower($tags));
        $searchtags=explode(' ',$t);
        $filtered=array();
        foreach($this->links as $l)
        { 
            $linktags = explode(' ',strtolower($l['tags']));
            if (count(array_intersect($linktags,$searchtags)) == count($searchtags))
                $filtered[$l['linkdate']] = $l;
        }
        krsort($filtered);
        return $filtered;
    }   

    // Returns the list of all tags
    // Output: associative array key=tags, value=0
    public function allTags()
    {
        $tags=array();
        foreach($this->links as $link)
            foreach(explode(' ',$link['tags']) as $tag)
                if (!empty($tag)) $tags[$tag]=0;
        ksort($tags); // FIXME: sort by usage ? That would be better.
        return $tags;
    }
    
}

// ------------------------------------------------------------------------------------------
// Ouput the last 50 links in RSS 2.0 format.
function showRSS()
{
    global $LINKSDB;
    header('Content-Type: application/xhtml+xml; charset=utf-8');
    $pageaddr=htmlspecialchars(serverUrl().$_SERVER["SCRIPT_NAME"]);
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">';
    echo '<channel><title>Shared links on '.$pageaddr.'</title><link>'.$pageaddr.'</link>';
    echo '<description>Shared links</description><language></language><copyright>'.$pageaddr.'</copyright>'."\n\n";
    $i=0;
    $keys=array(); foreach($LINKSDB as $key=>$value) { $keys[]=$key; }  // No, I can't use array_keys().
    while ($i<50 && $i<count($keys))
    {
        $link = $LINKSDB[$keys[$i]];
        $rfc822date = linkdate2rfc822($link['linkdate']);
        echo '<item><title>'.htmlspecialchars($link['title']).'</title><guid>'.htmlspecialchars($link['url']).'</guid><link>'.htmlspecialchars($link['url']).'</link><pubDate>'.htmlspecialchars($rfc822date).'</pubDate>';
        echo '<description><![CDATA['.htmlspecialchars($link['description']).']]></description></item>'."\n";      
        $i++;
    }
    echo '</channel></rss>';
    exit;
}

// ------------------------------------------------------------------------------------------
// Render HTML page:
function renderPage()
{
    global $STARTTIME;
    global $LINKSDB;
    
    // Well... rendering the page would be 100x better with the excellent Smarty, but I don't want to tie this minimalist project to 100+ files.
    // So I use a custom templating system.
    
    // -------- Display login form.
    if (startswith($_SERVER["QUERY_STRING"],'do=login'))
    {
        if (OPEN_SHAARLI) { header('Location: ?'); exit; }  // No need to login for open Shaarli
        if (!ban_canLogin())
        { 
            $loginform='<div id="headerform">You have been banned from login after too many failed attempts. Try later.</div>';
            $data = array('pageheader'=>$loginform,'body'=>'','onload'=>''); 
            templatePage($data);
            exit;
        }
        $returnurl_html = (isset($_SERVER['HTTP_REFERER']) ? '<input type="hidden" name="returnurl" value="'.htmlspecialchars($_SERVER['HTTP_REFERER']).'">' : '');        
        $loginform='<div id="headerform"><form method="post" name="loginform">Login: <input type="text" name="login">&nbsp;&nbsp;&nbsp;Password : <input type="password" name="password"> <input type="submit" value="Login" class="bigbutton"><input type="hidden" name="token" value="'.getToken().'">'.$returnurl_html.'</form></div>';
        $onload = 'onload="document.loginform.login.focus();"';
        $data = array('pageheader'=>$loginform,'body'=>'','onload'=>$onload); 
        templatePage($data);
        exit;
    }
    
    // -------- User wants to logout.
    if (startswith($_SERVER["QUERY_STRING"],'do=logout'))
    { 
        logout(); 
        header('Location: ?'); 
        exit; 
    }    
    
    // -------- User clicks on a tag in a link: The tag is added to the list of searched tags (searchtags=...)
    if (isset($_GET['addtag']))
    {
        // Get previous URL (http_referer) and add the tag to the searchtags parameters in query.
        if (empty($_SERVER['HTTP_REFERER'])) { header('Location: ?searchtags='.urlencode($_GET['addtag'])); exit; } // In case browser does not send HTTP_REFERER
        parse_str(parse_url($_SERVER['HTTP_REFERER'],PHP_URL_QUERY), $params);
        $params['searchtags'] = (empty($params['searchtags']) ?  trim($_GET['addtag']) : trim($params['searchtags'].' '.urlencode($_GET['addtag'])));
        unset($params['page']); // We also remove page (keeping the same page has no sense, since the results are different)
        header('Location: ?'.http_build_query($params));
        exit;
    }

    // -------- User clicks on a tag in result count: Remove the tag from the list of searched tags (searchtags=...)
    if (isset($_GET['removetag']))
    {
        // Get previous URL (http_referer) and remove the tag from the searchtags parameters in query.
        if (empty($_SERVER['HTTP_REFERER'])) { header('Location: ?'); exit; } // In case browser does not send HTTP_REFERER
        parse_str(parse_url($_SERVER['HTTP_REFERER'],PHP_URL_QUERY), $params);
        if (isset($params['searchtags']))
        {
            $tags = explode(' ',$params['searchtags']);
            $tags=array_diff($tags, array($_GET['removetag'])); // Remove value from array $tags.
            if (count($tags)==0) unset($params['searchtags']); else $params['searchtags'] = implode(' ',$tags);
            unset($params['page']); // We also remove page (keeping the same page has no sense, since the results are different)
        }
        header('Location: ?'.http_build_query($params));
        exit;
    }    
    
    // -------- User wants to change the number of links per page (linksperpage=...)
    if (isset($_GET['linksperpage']))
    {
        if (is_numeric($_GET['linksperpage'])) { $_SESSION['LINKS_PER_PAGE']=abs(intval($_GET['linksperpage'])); }
        header('Location: '.(empty($_SERVER['HTTP_REFERER'])?'?':$_SERVER['HTTP_REFERER']));
        exit;
    }
    
    
    // -------- Handle other actions allowed for non-logged in users:
    if (!isLoggedIn())
    {
        // User tries to post new link but is not loggedin:
        // Show login screen, then redirect to ?post=...
        if (isset($_GET['post'])) 
        {
            header('Location: ?do=login&post='.urlencode($_GET['post']).(isset($_GET['source'])?'&source='.urlencode($_GET['source']):'')); // Redirect to login page, then back to post link.
            exit;
        }
        
        // Show search form and display list of links.
        $searchform=<<<HTML
<div id="headerform" style="width:100%; white-space:nowrap;";>
    <form method="GET" name="searchform" style="display:inline;"><input type="text" name="searchterm" style="width:50%" value=""> <input type="submit" value="Search" class="bigbutton"></form>
    <form method="GET" name="tagfilter" style="display:inline;padding-left:24px;"><input type="text" name="searchtags" style="width:20%" value=""> <input type="submit" value="Filter by tag" class="bigbutton"></form>
</div>
HTML;
        $onload = 'document.searchform.searchterm.focus();';
        $data = array('pageheader'=>$searchform,'body'=>templateLinkList(),'onload'=>$onload); 
        templatePage($data);
        exit; // Never remove this one !
    }
    
    // -------- All other functions are reserved for the registered user:
    
    // -------- Display the Tools menu if requested (import/export/bookmarklet...)
    if (startswith($_SERVER["QUERY_STRING"],'do=tools'))
    {
        $pageabsaddr=serverUrl().$_SERVER["SCRIPT_NAME"]; // Why doesn't php have a built-in function for that ?
        // The javascript code for the bookmarklet:
        $toolbar= <<<HTML
<div id="headerform">
    <a href="?do=import"><b>Import</b></a> - <small>Import Netscape html bookmarks (as exported from Firefox, Chrome, Opera, delicious...)</small><br> 
    <a href="?do=export"><b>Export</b></a> - <small>Export Netscape html bookmarks (which can be imported in Firefox, Chrome, Opera, delicious...)</small><br>
    <a class="smallbutton" style="color:black;" onclick="alert('Drag this link to your bookmarks toolbar, or right-click it and choose Bookmark This Link...');return false;" href="javascript:javascript:(function(){var%20url%20=%20location.href;var%20title%20=%20document.title%20||%20url;window.open('{$pageabsaddr}?post='%20+%20encodeURIComponent(url)+'&amp;title='%20+%20encodeURIComponent(title)+'&amp;source=bookmarklet','_blank','menubar=no,height=400,width=608,toolbar=no,scrollbars=no,status=no');})();">Shaare link</a> - <small>Drag this link to your bookmarks toolbar (or right-click it and choose Bookmark This Link....). Then click "Shaare link" button in any page you want to share.</small>
</div>
HTML;
        $data = array('pageheader'=>$toolbar,'body'=>'','onload'=>''); 
        templatePage($data);
        exit;
    }
    
    // -------- User wants to add a link without using the bookmarklet: show form.
    if (startswith($_SERVER["QUERY_STRING"],'do=addlink'))
    {
        $onload = 'document.addform.post.focus();';
        $addform= '<div id="headerform"><form method="GET" action="" name="addform"><input type="text" name="post" style="width:70%;"> <input type="submit" value="Add link" class="bigbutton"></div>';
        $data = array('pageheader'=>$addform,'body'=>'','onload'=>$onload); 
        templatePage($data);
        exit;
    }    
    
    // -------- User clicked the "Save" button when editing a link: Save link to database.
    if (isset($_POST['save_edit']))
    {
        if (!tokenOk($_POST['token'])) die('Wrong token.'); // Go away !
        $linkdate=$_POST['lf_linkdate'];
        $link = array('title'=>trim($_POST['lf_title']),'url'=>trim($_POST['lf_url']),'description'=>trim($_POST['lf_description']),'private'=>(isset($_POST['lf_private']) ? 1 : 0),
                      'linkdate'=>$linkdate,'tags'=>trim($_POST['lf_tags']));        
        if ($link['title']=='') $link['title']=$link['url']; // If title is empty, use the URL as title.
        $LINKSDB[$linkdate] = $link;
        $LINKSDB->savedb(); // save to disk
        
        // If we are called from the bookmarklet, we must close the popup:
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After saving the link, redirect to the page the user was on.
        exit;
    } 
    
    // -------- User clicked the "Cancel" button when editing a link.
    if (isset($_POST['cancel_edit']))
    {
        // If we are called from the bookmarklet, we must close the popup;
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After canceling, redirect to the page the user was on.
        exit;    
    }

    // -------- User clicked the "Delete" button when editing a link : Delete link from database.
    if (isset($_POST['delete_link']))
    {
        if (!tokenOk($_POST['token'])) die('Wrong token.');
        // We do not need to ask for confirmation:
        // - confirmation is handled by javascript
        // - we are protected from XSRF by the token.
        $linkdate=$_POST['lf_linkdate'];
        unset($LINKSDB[$linkdate]);
        $LINKSDB->savedb(); // save to disk
        // If we are called from the bookmarklet, we must close the popup:
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After deleting the link, redirect to the page the user was on.
        exit;
    }    
    
    // -------- User clicked the "EDIT" button on a link: Display link edit form.
    if (isset($_GET['edit_link']))  
    {
        $link = $LINKSDB[$_GET['edit_link']];  // Read database
        if (!$link) { header('Location: ?'); exit; } // Link not found in database.
        list($editform,$onload)=templateEditForm($link);
        $data = array('pageheader'=>$editform,'body'=>'','onload'=>$onload); 
        templatePage($data);    
        exit;       
    }
    
    // -------- User want to post a new link: Display link edit form.
    if (isset($_GET['post']))
    {
        $url=$_GET['post'];

        // We remove the annoying parameters added by FeedBurner and GoogleFeedProxy (?utm_source=...)
        $i=strpos($url,'&utm_source='); if ($i) $url=substr($url,0,$i);
        $i=strpos($url,'?utm_source='); if ($i) $url=substr($url,0,$i);
        
        $link_is_new = false;
        $link = $LINKSDB->getLinkFromUrl($url); // Check if URL is not already in database (in this case, we will edit the existing link)
        if (!$link) 
        {
            $link_is_new = true;  // This is a new link
            $linkdate = strval(date('Ymd_His'));
            $title = (empty($_GET['title']) ? '' : $_GET['title'] ); // Get title if it was provided in URL (by the bookmarklet).
            $description=''; $tags=''; $private=0;
            if (parse_url($url,PHP_URL_SCHEME)=='') $url = 'http://'.$url;                
            // If this is an HTTP link, we try go get the page to extact the title (otherwise we will to straight to the edit form.)
            if (empty($title) && parse_url($url,PHP_URL_SCHEME)=='http')
            {
                list($status,$headers,$data) = getHTTP($url,4); // Short timeout to keep the application responsive.
                // FIXME: Decode charset according to specified in either 1) HTTP response headers or 2) <head> in html 
                if (strpos($status,'200 OK')) $title=html_entity_decode(html_extract_title($data),ENT_QUOTES,'UTF-8');
            }
            $link = array('linkdate'=>$linkdate,'title'=>$title,'url'=>$url,'description'=>$description,'tags'=>$tags,'private'=>0); 
        }
        list($editform,$onload)=templateEditForm($link,$link_is_new); 
        $data = array('pageheader'=>$editform,'body'=>'','onload'=>$onload); 
        templatePage($data);    
        exit;
    }
    
    // -------- Export as Netscape Bookmarks HTML file.
    if (startswith($_SERVER["QUERY_STRING"],'do=export'))
    {    
        header('Content-Type: text/html; charset=utf-8');
        header('Content-disposition: attachment; filename=bookmarks_'.strval(date('Ymd_His')).'.html');
        echo <<<HTML
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<!-- This is an automatically generated file.
     It will be read and overwritten.
     DO NOT EDIT! -->
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
HTML;
        foreach($LINKSDB as $link)
        {
            echo '<DT><A HREF="'.htmlspecialchars($link['url']).'" ADD_DATE="'.linkdate2timestamp($link['linkdate']).'" PRIVATE="'.$link['private'].'"';
            if ($link['tags']!='') echo ' TAGS="'.htmlspecialchars(str_replace(' ',',',$link['tags'])).'"';
            echo '>'.htmlspecialchars($link['title'])."</A>\n";
            if ($link['description']!='') echo '<DD>'.htmlspecialchars($link['description'])."\n";
        }
        echo '<!-- Shaarli bookmarks export on '.date('Y/m/d H:i:s')."-->\n";
        exit;
    }            

    // -------- User is uploading a file for import
    if (startswith($_SERVER["QUERY_STRING"],'do=upload'))
    {
        // If file is too big, some form field may be missing.
        if (!isset($_POST['token']) || (!isset($_FILES)) || (isset($_FILES['filetoupload']['size']) && $_FILES['filetoupload']['size']==0))
        {
            $returnurl = ( empty($_SERVER['HTTP_REFERER']) ? '?' : $_SERVER['HTTP_REFERER'] );
            echo '<script language="JavaScript">alert("The file you are trying to upload is probably bigger than what this webserver can accept ('.getMaxFileSize().' bytes). Please upload in smaller chunks.");document.location=\''.htmlspecialchars($returnurl).'\';</script>';
            exit;
        }         
        if (!tokenOk($_POST['token'])) die('Wrong token.');
        importFile();
        exit;
    }     
    
    // -------- Show upload/import dialog:
    if (startswith($_SERVER["QUERY_STRING"],'do=import'))
    {    
        $token = getToken();
        $maxfilesize=getMaxFileSize();
        $onload = 'onload="document.uploadform.filetoupload.focus();"';
        $uploadform=<<<HTML
<div id="headerform">
Import Netscape html bookmarks (as exported from Firefox/Chrome/Opera/delicious/diigo...) (Max: {$maxfilesize} bytes).
<form method="POST" action="?do=upload" enctype="multipart/form-data" name="uploadform">
    <input type="hidden" name="token" value="{$token}">        
    <input type="file" name="filetoupload" size="80">
    <input type="hidden" name="MAX_FILE_SIZE" value="{$maxfilesize}">
    <input type="submit" name="import_file" value="Import" class="bigbutton">   
</form>
</div>
HTML;
        $data = array('pageheader'=>$uploadform,'body'=>'','onload'=>$onload  ); 
        templatePage($data);
        exit;
    }    

    // -------- Otherwise, simply display search form and links:
    $searchform=<<<HTML
<div id="headerform" style="width:100%; white-space:nowrap;";>
    <form method="GET" name="searchform" style="display:inline;"><input type="text" name="searchterm" style="width:50%" value=""> <input type="submit" value="Search" class="bigbutton"></form>
    <form method="GET" name="tagfilter" style="display:inline;padding-left:24px;"><input type="text" name="searchtags" style="width:20%" value=""> <input type="submit" value="Filter by tag" class="bigbutton"></form>
</div>
HTML;
    $onload = 'document.searchform.searchterm.focus();';
    $data = array('pageheader'=>$searchform,'body'=>templateLinkList(),'onload'=>$onload); 
    templatePage($data);
    exit;
}    

// -----------------------------------------------------------------------------------------------
// Process the import file form.
function importFile()
{
    global $LINKSDB;
    $filename=$_FILES['filetoupload']['name'];
    $filesize=$_FILES['filetoupload']['size'];    
    $data=file_get_contents($_FILES['filetoupload']['tmp_name']);

    // Sniff file type:
    $type='unknown';
    if (startsWith($data,'<!DOCTYPE NETSCAPE-Bookmark-file-1>')) $type='netscape'; // Netscape bookmark file (aka Firefox).
    
    // Then import the bookmarks.
    if ($type=='netscape')
    {
        // This is a standard Netscape-style bookmark file.
        // This format is supported by all browsers (except IE, of course), also delicious, diigo and others.      
        // I didn't want to use DOM... anyway, this is FAST (less than 1 second to import 7200 links (2.1 Mb html file)).
        $before=count($LINKSDB); 
        foreach(explode('<DT>',$data) as $html) // explode is very fast
        {
            $link = array('linkdate'=>'','title'=>'','url'=>'','description'=>'','tags'=>'','private'=>0);  
            $d = explode('<DD>',$html);
            if (startswith($d[0],'<A '))
            {
                $link['description'] = (isset($d[1]) ? trim($d[1]) : '');  // Get description (optional)
                preg_match('!<A .*?>(.*?)</A>!i',$d[0],$matches); $link['title'] = (isset($matches[1]) ? trim($matches[1]) : '');  // Get title
                preg_match_all('! ([A-Z_]+)=\"(.*?)"!i',$html,$matches,PREG_SET_ORDER);  // Get all other attributes
                foreach($matches as $m)
                {
                    $attr=$m[1]; $value=$m[2];
                    if ($attr=='HREF') $link['url']=$value;
                    elseif ($attr=='ADD_DATE') $link['linkdate']=date('Ymd_His',intval($value));
                    elseif ($attr=='PRIVATE') $link['private']=($value=='0'?0:1);
                    elseif ($attr=='TAGS') $link['tags']=str_replace(',',' ',$value);
                }     
                if ($link['linkdate']!='' && $link['url']!='')  $LINKSDB[$link['linkdate']] = $link;
            }     
        }
        $import_count = count($LINKSDB)-$before;
        $LINKSDB->savedb();
        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) was successfully imported: '.$import_count.' new links.");document.location=\'?\';</script>';            
    }
    else
    {
        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) has an unknown file format. Nothing was imported.");document.location=\'?\';</script>';
    }
}    

// -----------------------------------------------------------------------------------------------
/* Template for the edit link form
    Input: $link : link to edit (assocative array item as returned by the LINKDB class)
Output: An array : (string) : The html code of the edit link form.
                   (string) : The proper onload to use in body.
    Example: list($html,$onload)=templateEditForm($mylinkdb['20110805_124532']);
             echo $html;
*/
function templateEditForm($link,$link_is_new=false)
{
    $url=htmlspecialchars($link['url']);
    $title=htmlspecialchars($link['title']);
    $tags=htmlspecialchars($link['tags']);
    $description=htmlspecialchars($link['description']);    
    $private = ($link['private']==0 ? '' : 'checked="yes"');   
    
    // Automatically focus on empty fields:
    $onload='onload="document.linkform.lf_tags.focus();"';
    if ($description=='') $onload='onload="document.linkform.lf_description.focus();"';
    if ($title=='') $onload='onload="document.linkform.lf_title.focus();"';    
    
    // Do not show "Delete" button if this is a new link.
    $delete_button = '<input type="submit" value="Delete" name="delete_link" class="bigbutton" style="margin-left:180px;" onClick="return confirmDeleteLink();">';
    if ($link_is_new) $delete_button='';
    
    $token=getToken(); // XSRF protection. 
    $returnurl_html = (isset($_SERVER['HTTP_REFERER']) ? '<input type="hidden" name="returnurl" value="'.htmlspecialchars($_SERVER['HTTP_REFERER']).'">' : '');
    $editlinkform=<<<HTML
<div id="editlinkform">
    <form method="post" name="linkform">
        <input type="hidden" name="lf_linkdate" value="{$link['linkdate']}">
        <i>URL</i><br><input type="text" name="lf_url" value="{$url}" style="width:100%"><br>
        <i>Title</i><br><input type="text" name="lf_title" value="{$title}" style="width:100%"><br>
        <i>Description</i><br><textarea name="lf_description" rows="4" cols="25" style="width:100%">{$description}</textarea><br>
        <i>Tags</i><br><input type="text" id="lf_tags" name="lf_tags" value="{$tags}" style="width:100%"><br>
        <input type="checkbox" {$private} style="margin:7 0 10 0;" name="lf_private">&nbsp;<i>Private</i><br>
        <input type="submit" value="Save" name="save_edit" class="bigbutton" style="margin-left:40px;">
        <input type="submit" value="Cancel" name="cancel_edit" class="bigbutton" style="margin-left:40px;">
        {$delete_button}
        <input type="hidden" name="token" value="{$token}">
        {$returnurl_html}
    </form>
</div>    
HTML;
    return array($editlinkform,$onload);
}        


// -----------------------------------------------------------------------------------------------
// Template for the list of links.
// Returns html code to show the list of link according to parameters passed in URL (search terms, page...)
function templateLinkList()
{     
    global $LINKSDB;
    
    // Search according to entered search terms:
    $linksToDisplay=array();
    $searched='';
    if (!empty($_GET['searchterm'])) // Fulltext search
    {
        $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
        $searched='&nbsp;<b>'.count($linksToDisplay).' results for <i>'.htmlspecialchars($_GET['searchterm']).'</i></b>:';
    }
    elseif (!empty($_GET['searchtags'])) // Search by tag
    {
        $linksToDisplay = $LINKSDB->filterTags($_GET['searchtags']);
        $tagshtml=''; foreach(explode(' ',$_GET['searchtags']) as $tag) $tagshtml.='<span class="linktag" title="Remove tag"><a href="?removetag='.htmlspecialchars($tag).'">'.htmlspecialchars($tag).' <span style="border-left:1px solid #aaa; padding-left:5px; color:#6767A7;">x</span></a></span> ';
        $searched='&nbsp;<b>'.count($linksToDisplay).' results for tags '.$tagshtml.':</b>';    
    }
    else
        $linksToDisplay = $LINKSDB;  // otherwise, display without filtering.
   
    $linklist='';
    $actions='';
    
    // Handle paging.
    /* Can someone explain to me why you get the following error when using array_keys() on an object which implements the interface ArrayAccess ???
       "Warning: array_keys() expects parameter 1 to be array, object given in ... "
       If my class implements ArrayAccess, why won't array_keys() accept it ?  ( $keys=array_keys($linksToDisplay); )
    */
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; } // Stupid and ugly. Thanks php.
    $pagecount = ceil(count($keys)/$_SESSION['LINKS_PER_PAGE']);
    $pagecount = ($pagecount==0 ? 1 : $pagecount);
    $page=( empty($_GET['page']) ? 1 : intval($_GET['page']));
    $page = ( $page<1 ? 1 : $page );
    $page = ( $page>$pagecount ? $pagecount : $page );
    $i = ($page-1)*$_SESSION['LINKS_PER_PAGE']; // Start index.
    $end = $i+$_SESSION['LINKS_PER_PAGE'];    
    while ($i<$end && $i<count($keys))
    {  
        $link = $linksToDisplay[$keys[$i]];
        $description=$link['description'];
        $title=$link['title'];
        $classprivate = ($link['private']==0 ? '' : 'class="private"');
        if (isLoggedIn()) $actions=' <form method="GET" class="buttoneditform"><input type="hidden" name="edit_link" value="'.$link['linkdate'].'"><input type="submit" value="Edit" class="smallbutton"></form>';
        $tags='';
        if ($link['tags']!='') foreach(explode(' ',$link['tags']) as $tag) { $tags.='<span class="linktag" title="Add tag"><a href="?addtag='.htmlspecialchars($tag).'">'.htmlspecialchars($tag).'</a></span> '; }
        $linklist.='<li '.$classprivate.'><span class="linktitle"><a href="'.htmlspecialchars($link['url']).'">'.htmlspecialchars($title).'</a></span>'.$actions.'<br>';
        if ($description!='') $linklist.='<div class="linkdescription">'.nl2br(htmlspecialchars($description)).'</div><br>';
        $linklist.='<span class="linkdate">'.htmlspecialchars(linkdate2locale($link['linkdate'])).' - </span><span class="linkurl">'.htmlspecialchars($link['url']).'</span><br>'.$tags."</li>\n";  
        $i++;
    } 
    
    // Show paging.
    $searchterm= ( empty($_GET['searchterm']) ? '' : '&searchterm='.$_GET['searchterm'] );
    $searchtags= ( empty($_GET['searchtags']) ? '' : '&searchtags='.$_GET['searchtags'] );
    $paging=''; 
    if ($i!=count($keys)) $paging.='<a href="?page='.($page+1).$searchterm.$searchtags.'">&#x25C4;Older</a>';
    $paging.= '<span style="color:#fff; padding:0 20 0 20;">page '.$page.' / '.$pagecount.'</span>';
    if ($page>1) $paging.='<a href="?page='.($page-1).$searchterm.$searchtags.'">Newer&#x25BA;</a>';
    $linksperpage = <<<HTML
<div style="float:right; padding-right:5px;">
Links per page: <a href="?linksperpage=20">20</a> <a href="?linksperpage=50">50</a> <a href="?linksperpage=100">100</a>
 <form method="GET" style="display:inline;"><input type="text" name="linksperpage" size="2" style="height:15px;"></form></div>
HTML;
    $paging = '<div class="paging">'.$linksperpage.$paging.'</div>';
    $linklist='<div id="linklist">'.$paging.$searched.'<ul>'.$linklist.'</ul>'.$paging.'</div>';
    return $linklist;
}

// -----------------------------------------------------------------------------------------------
// Template for the whole page.
/* Input: $data (associative array).
                Keys: 'body' : body of HTML document
                      'pageheader' : html code to show in page header (top of page)
                      'onload' : optional onload javascript for the <body>
*/
function templatePage($data)
{
    global $STARTTIME;
    global $LINKSDB;
    $shaarli_version = shaarli_version;
    $linkcount = count($LINKSDB);
    $open='';
    if (OPEN_SHAARLI)
    {
        $menu=' <a href="?do=tools">Tools</a> &nbsp;<a href="?do=addlink"><b>Add link</b></a>';
        $open='Open ';
    }
    else
        $menu=(isLoggedIn() ? ' <a href="?do=logout">Logout</a> &nbsp;<a href="?do=tools">Tools</a> &nbsp;<a href="?do=addlink"><b>Add link</b></a>' : ' <a href="?do=login">Login</a>');  
    foreach(array('pageheader','body','onload') as $k) // make sure all required fields exist (put an empty string if not).
    {
        if (!array_key_exists($k,$data)) $data[$k]='';
    }
    $jsincludes=''; $jsincludes_bottom = '';
    if (OPEN_SHAARLI || isLoggedIn())
    { 
        $jsincludes='<script language="JavaScript" src="jquery.min.js"></script><script language="JavaScript" src="jquery-ui.custom.min.js"></script>'; 
        $source = serverUrl().$_SERVER['SCRIPT_NAME'].'?ws=tags';  
        $jsincludes_bottom = <<<JS
<script language="JavaScript">             
$(document).ready(function() 
{
    $('#lf_tags').autocomplete({source:'{$source}',minLength:0});
});        
</script>    
JS;
    }
    $feedurl=htmlspecialchars(serverUrl().$_SERVER['SCRIPT_NAME'].'?do=rss');
    echo <<<HTML
<html>
<head>
<title>{$open}Shaarli - Let's shaare your links...</title>
<link rel="alternate" type="application/rss+xml" href="{$feedurl}">
{$jsincludes}
<style type="text/css">
<!--
/* CSS Reset from Yahoo to cope with browsers CSS inconsistencies. */
/*
Copyright (c) 2010, Yahoo! Inc. All rights reserved. Code licensed under the BSD License: http://developer.yahoo.com/yui/license.html
version: 2.8.2r1
*/
html{color:#000;background:#FFF;}body,div,dl,dt,dd,ul,ol,li,h1,h2,h3,h4,h5,h6,pre,code,form,fieldset,legend,input,button,textarea,p,blockquote,th,td{margin:0;padding:0;}table{border-collapse:collapse;border-spacing:0;}fieldset,img{border:0;}address,caption,cite,code,dfn,em,strong,th,var,optgroup{font-style:inherit;font-weight:inherit;}del,ins{text-decoration:none;}li{list-style:none;}caption,th{text-align:left;}h1,h2,h3,h4,h5,h6{font-size:100%;font-weight:normal;}q:before,q:after{content:'';}abbr,acronym{border:0;font-variant:normal;}sup{vertical-align:baseline;}sub{vertical-align:baseline;}legend{color:#000;}input,button,textarea,select,optgroup,option{font-family:inherit;font-size:inherit;font-style:inherit;font-weight:inherit;}input,button,textarea,select{*font-size:100%;}

body { font-family: "Trebuchet MS",Verdana,Arial,Helvetica,sans-serif; font-size:10pt; background-color: #ffffff; } 
input { border: 1px solid #aaa; background-color:#F0F0FF; padding: 2 5 2 5; -moz-box-shadow: inset 2px 2px 3px #aaaaaa; -webkit-box-shadow: inset 2px 2px 3px #aaaaaa; box-shadow: inset 2px 2px 3px #aaaaaa; } 
textarea { border: 1px solid #aaa; background-color:#F0F0FF; padding: 2 5 2 5; -moz-box-shadow: inset 2px 2px 3px #B2B2C4; -webkit-box-shadow: inset 2px 2px 3px #B2B2C4; box-shadow: inset 2px 2px 3px #B2B2C4; } 
/* I don't give a shit about IE. He can't understand selectors such as input[type='submit']. */

.bigbutton {border-style:outset;border-width:2px;padding:3px 6px;background-color:rgb(212,212,212);font-family:Tahoma,Verdana,Arial,Helvetica,sans-serif;font-size:8pt;-moz-border-radius:0.5em;border-radius:0.5em;} 
.smallbutton {border-style:outset;border-width:2px;padding:0px 4px;background-color:rgb(212,212,212);font-family:Tahoma,Verdana,Arial,Helvetica,sans-serif;font-size:8pt;-moz-border-radius:0.5em;border-radius:0.5em;} 
#pageheader
{
color:#eee;
border-bottom: 1px solid #aaa;    
background-color: #6A6A6A;
background-image: -webkit-gradient(linear, left top, left bottom, from(#6A6A6A), to(#303030)); /* Saf4+, Chrome */
background-image: -webkit-linear-gradient(top, #6A6A6A, #303030); /* Chrome 10+, Saf5.1+ */
background-image:    -moz-linear-gradient(top, #6A6A6A, #303030); /* FF3.6 */
background-image:     -ms-linear-gradient(top, #6A6A6A, #303030); /* IE10 */
background-image:      -o-linear-gradient(top, #6A6A6A, #303030); /* Opera 11.10+ */
background-image:         linear-gradient(top, #6A6A6A, #303030);
filter: progid:DXImageTransform.Microsoft.gradient(startColorStr='#6A6A6A', EndColorStr='#303030'); /* IE6-IE9 */
padding-bottom: 5px;
}
#pageheader a:link { color:#bbb; text-decoration:none;}
#pageheader a:visited { color:#bbb; text-decoration:none;}
#pageheader a:hover { color:#FFFFC9; text-decoration:none;}
#pageheader a:active { color:#bbb; text-decoration:none;} 
.paging { background-color:#777; color:#ccc; text-align:center; padding:0 0 3 0;}
.paging a:link { color:#ccc; text-decoration:none;}
.paging a:visited { color:#ccc;  }
.paging a:hover {  color:#FFFFC9;  }
.paging a:active { color:#fff;  }
#headerform { padding:5 5 5 15;  }
#editlinkform {  padding:5 5 5 15px; width:80%; }
#linklist li { padding:4 10 8 20; border-bottom: 1px solid #bbb;}
#linklist li.private { background-color: #ccc; border-left:8px solid #888; }
.linktitle { font-size:14pt; font-weight:bold; }
.linktitle a { text-decoration: none; color:#0000EE; }
.linktitle a:hover { text-decoration: underline; }
.linktitle a:visited { color:#0000BB; }
.linkdate { font-size:8pt; color:#888; }
.linkurl { font-size:8pt; color:#4BAA74; }
.linkdescription { color:#000; margin-top:0px; margin-bottom:0px; font-weight:normal; }
.linktag { font-size:9pt; color:#777; background-color:#ddd; padding:0 6 0 6; -moz-box-shadow: inset 2px 2px 3px #ffffff; -webkit-box-shadow: inset 2px 2px 3px #ffffff; box-shadow: inset 2px 2px 3px ffffff;
border-bottom:1px solid #aaa; border-right:1px solid #aaa;  }
.linktag a { color:#777; text-decoration:none;  }
.buttoneditform { display:inline; }
#footer { font-size:8pt; text-align:center; border-top:1px solid #ddd; color: #888; }

/* Minimal customisation for jQuery widgets */
.ui-autocomplete { background-color:#fff; padding-left:5px;}
.ui-state-hover { background-color: #604dff; color:#fff; }

-->
</style>
</head>
<body {$data['onload']}>
<div id="pageheader"><div style="float:right; font-style:italic; color:#bbb; text-align:right; padding:0 5 0 0;">Shaare your links...<br>{$linkcount} links</div>
    <b><i>{$open}Shaarli {$shaarli_version}</i></b> - <a href="?">Home</a>&nbsp;{$menu}&nbsp;<a href="{$feedurl}" style="padding-left:30px;">RSS Feed</a>
{$data['pageheader']}    
</div>
{$data['body']}

HTML;
    $exectime = round(microtime(true)-$STARTTIME,4);
    echo '<div id="footer"><b><a href="http://sebsauvage.net/wiki/doku.php?id=php:shaarli">Shaarli '.shaarli_version.'</a></b> - The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net<br>Who gives a shit that this page was generated in '.$exectime.' seconds&nbsp;?</div>';
    if (isLoggedIn()) echo '<script language="JavaScript">function confirmDeleteLink() { var agree=confirm("Are you sure you want to delete this link ?"); if (agree) return true ; else return false ; }</script>';
    echo $jsincludes_bottom.'</body></html>';
}

// -----------------------------------------------------------------------------------------------
// Installation
// This function should NEVER be called if the file data/config.php exists.
function install()
{
    if (!empty($_POST['setlogin']) && !empty($_POST['setpassword']))
    {
        $tz=(empty($_POST['settimezone']) ? 'UTC':$_POST['settimezone']);
        // Everything is ok, let's create config file.
        $salt=sha1(uniqid('',true).'_'.mt_rand()); // Salt renders rainbow-tables attacks useless.
        $hash = sha1($_POST['setpassword'].$_POST['setlogin'].$salt);
        $config='<?php $GLOBALS[\'login\']='.var_export($_POST['setlogin'],true).'; $GLOBALS[\'hash\']='.var_export($hash,true).'; $GLOBALS[\'salt\']='.var_export($salt,true).'; date_default_timezone_set('.var_export($tz,true).'); ?>';
        if (!file_put_contents(CONFIG_FILE,$config) || strcmp(file_get_contents(CONFIG_FILE),$config)!=0)
        {
            echo '<script language="JavaScript">alert("Shaarli could not create the config file. Please make sure Shaarli has the right to write in the folder is it installed in.");document.location=\'?\';</script>';
            exit;
        }
        echo '<script language="JavaScript">alert("Shaarli is now configured. Please enter your login/password and start shaaring your links !");document.location=\'?do=login\';</script>';        
        exit;            
   }
    // Display config form:
    $timezoneselect='';
    if (function_exists('timezone_identifiers_list')) // because of old php version (5.1) which can be found on free.fr
    {
        $timezones='';
        foreach(timezone_identifiers_list() as $tz) $timezones.='<option value="'.htmlspecialchars($tz).'">'.htmlspecialchars($tz)."</option>\n";
        $timezoneselect='Timezone: <select name="settimezone"><option value="" selected>(please select:)</option>'.$timezones.'</select><br><br>';
    }
    echo <<<HTML
<html><title>Shaarli - Configuration</title><style type="text/css">
body { font-family: "Trebuchet MS",Verdana,Arial,Helvetica,sans-serif; font-size:10pt; background-color: #ffffff; } 
input { border: 1px solid #aaa; background-color:#F0F0FF; padding: 2 5 2 5; -moz-box-shadow: inset 2px 2px 3px #aaaaaa; -webkit-box-shadow: inset 2px 2px 3px #aaaaaa; box-shadow: inset 2px 2px 3px #aaaaaa; } 
.bigbutton {border-style:outset;border-width:2px;padding:3px 6px;background-color:rgb(212,212,212);font-family:Tahoma,Verdana,Arial,Helvetica,sans-serif;font-size:8pt;-moz-border-radius:0.5em;border-radius:0.5em;} 
</style></head><body onload="document.configform.setlogin.focus();"><h1>Shaarli - Shaare your links...</h1>It looks like it's the first time you run Shaarli. Please chose a login/password and a timezone:<br>
<form method="POST" action="" name="configform" style="border:1px solid black; padding:10 10 10 10;">
Login: <input type="text" name="setlogin"><br><br>Password: <input type="password" name="setpassword"><br><br>
{$timezoneselect}
<input type="submit" name="Save" value="Save config" class="bigbutton"></form></body></html>
HTML;
    exit;
}

// Webservices (for use with jQuery/jQueryUI)
// eg.  index.php?ws=tags&term=minecr
function processWS()
{
    if (empty($_GET['ws']) || empty($_GET['term'])) return;
    $term = $_GET['term'];
    global $LINKSDB;
    header('Content-Type: application/json; charset=utf-8');

    // Search in tags
    if ($_GET['ws']=='tags')
    { 
        $tags=explode(' ',$term); $last = array_pop($tags); // Get the last term ("a b c d" ==> "a b c", "d")
        $addtags=''; if ($tags) $addtags=implode(' ',$tags).' '; // We will pre-pend previous tags
        $suggested=array();
        /* To speed up things, we store list of tags in session */
        if (empty($_SESSION['tags'])) $_SESSION['tags'] = $LINKSDB->allTags(); 
        foreach($_SESSION['tags'] as $key=>$value)
        {
            if (startsWith($key,$last,$case=false)) $suggested[$addtags.$key.' ']=0;
        }      
        echo json_encode(array_keys($suggested));
        exit;
    }
}

$LINKSDB=new linkdb(isLoggedIn() || OPEN_SHAARLI);  // Read links from database (and filter private links if used it not logged in).
if (startswith($_SERVER["QUERY_STRING"],'ws=')) { processWS(); exit; } // Webservices (for jQuery/jQueryUI)
if (!isset($_SESSION['LINKS_PER_PAGE'])) $_SESSION['LINKS_PER_PAGE']=LINKS_PER_PAGE;
if (startswith($_SERVER["QUERY_STRING"],'do=rss')) { showRSS(); exit; }
renderPage();
?>