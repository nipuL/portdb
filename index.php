<?php

require('DB.php');

$dbhandle = "sqlite:////home/crux/public_html/local/portdb.db";

function nospam($mail) {
  $mail = preg_replace("/\@/", " at ", $mail);
  $mail = preg_replace("/\./", " dot ", $mail);
  return htmlspecialchars($mail);
}

function sanitize($str) {
  return $str;
}

class Repo
{
  public $name;
  public $maintainer;
  public $type;
  public $url;
  public $count;

  function __construct($row) {
    $this->name = trim($row["collname"]);
    $this->maintainer = trim($row["maintainer"]);
    $this->type = trim($row["colltype"]);
    $this->url = trim($row["url"]);
    $this->count = trim($row["tot"]);
  }

  function toHTML() {
    return "<td><a href=\"?a=repo&q={$this->name}\">{$this->name}</a></td>
            <td>{$this->count}</td>
            <td><a href=\"?a=getup&q={$this->name}\">{$this->type}</a></td>
            <td>{$this->nospam()}</td>
            <td>{$this->urlToHTML()}</td>";
  }

  function toXML() {
    return "<repo>
              <name>{$this->name}</name>
              <maintainer>{$this->nospam()}</maintainer>
              <type>{$this->type}</type>
              <url>{$this->url}</url>
              <ports>{$this->count}</ports>
            </repo>";
  }

  function urlToHTML() {
    if ($this->type == "httpup") {
      return "<a href=\"{$this->url}\">{$this->url}</a>";
    }
    else {
      return $this->url;
    }
  }

  function nospam() {
    return nospam($this->maintainer);
  }
}

class Port
{
  public $name;
  public $repo;

  function __construct($row) {
    $this->name = trim($row["portname"]);
    $this->repo = new Repo($row);
  }

  function toXML() {
    return "<port>
              <name>{$this->name}</name>
              <repo>{$this->repo->name}</repo>
              {$this->filesToXML()}
              <command>{$this->downloadCommand()}</command>
            </port>";

  }

  function toHTML() {
    return "<td>{$this->name}</td>
            <td><a href=\"?a=repo&q={$this->repo->name}\">{$this->repo->name}</a></td>
            <td>{$this->filesToHTML()}</td>
            <td>{$this->downloadCommand()}</td>";
  }

  function filesToXML() {
    $xml = "";
    if ($this->repo->type == "httpup") {
      $base_url = "{$this->repo->url}/{$this->name}/";
      $xml = "<files>";
      $xml .= "<pkgfile>{$base_url}Pkgfile</pkgfile>";
      $xml .= "<footprint>{$base_url}.footprint</footprint>";
      $xml .= "<md5sum>{$base_url}.md5sum</md5sum>";
      $xml .= "</files>"; 
    }
    return $xml;
  }

  function filesToHTML() {
    $html = "";
    if ($this->repo->type == "httpup") {
      $base_url = "{$this->repo->url}/{$this->name}/";
      $html = "<a href=\"{$base_url}Pkgfile\">P</a> ";
      $html .= "<a href=\"{$base_url}.footprint\">F</a> ";
      $html .= "<a href=\"{$base_url}.md5sum\">M</a>";
    }
    return $html;
  }

  function downloadCommand() {
    switch ($this->repo->type) {
    case "httpup":
      return "httpup sync {$this->repo->url}#{$this->name} {$this->name}";
    case "rsync":
      return "rsync -aqz {$this->repo->url}{$this->name}/ {$this->name}";
    default:
      return "unknown repo type";
    }
  }
}

class Duplicate
{
  public $name;
  public $count;

  function __construct($row) {
    $this->name = $row["portname"];
    $this->count = $row["dup"];
  }

  function toHTML() {
    return "<td>{$this->name}</td>
            <td>Found <a href=\"?a=search&q={$this->name}&s=true\">{$this->count} in repository</a></td>";
  }

  function toXML() {
    return "<duplicate>
              <name>{$this->name}</name>
              <count>{$this->count}</count>
            </duplicate>";
  }
}

class PortDb
{
  public $db;
  public $last_result;

  function __construct($dbhandle) {
    $this->db =& DB::connect($dbhandle);
    if (DB::isError($this->db)) die ("Can not connect to database");
    $this->db->setFetchMode(DB_FETCHMODE_ASSOC);
  }

  function lazy_programmer() {
    die("LAZY PROGRAMMER ERROR");
  }

  function getQuery() {
    try {
      $sth = $this->db->prepare($this->sql);
      $args = func_get_args();
      $res = $this->db->execute($sth, $args);
      if (DB::isError($res)) die ($res->getUserInfo());
      $this->last_result = array();
      while ($row =& $res->fetchRow()) {
        array_push($this->last_result, $row);
      }
      return $this->last_result;
    }
    catch (Exception $exception) {
      die($exception->getMessage());
    }
  }

  function doQuery() {
    $this->lazy_programmer();
  }

  function htmlHeader() {
    return file_get_contents("header.html");
  }

  function htmlFooter() {
    return file_get_contents("footer.html");
  }

  function toHTML($start, $headers, $items) {
    $html = $this->htmlHeader();
    $html .= $start;
    $html .= "<table class=\"listing\">";
    $html .= "<thead><tr>";
    foreach ($headers as $header) {
      $html .= "<th>{$header}</th>";
    }
    $html .= "</tr></thead>";
    foreach ($items as $item) {
      $html .= "<tr>" . $item->toHTML() . "</tr>";
    }
    $html .= "</table>";
    $html .= $this->htmlFooter();
    return $html;
  }

  function toXML($root, $items) {
    header("Content-type: text/xml");
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
    $xml .= "<{$root}>";
    foreach ($items as $item) {
      $xml .= $item->toXML();
    }
    $xml .= "</{$root}>";
    return $xml;
  }
}

class RepoList extends PortDb
{
  public $sql = 'select collname,maintainer,colltype,url,count(*) as tot from collections
                    join ports on collection=collname
                    group by collname order by collections.collid';

  function doQuery() {
    $rows = $this->getQuery();
    $repos = array();
    foreach($rows as $row) {
      $repo = new Repo($row);
      array_push($repos, $repo);
    }
    return $repos;
  }

  function toXML($repos) {
    return parent::toXML("repos", $repos);
  }

  function toHTML($repos) {
    $start = "<h2>Overview of available repositories</h2>";
    $headers = array("Repo Name", "# ports", "Type", "Maintainer", "Repo URL");
    return parent::toHtml($start, $headers, $repos);
  }
}

class PortList extends PortDb
{
  public $sql = "select ports.portname as portname,
                           collections.collname as collname,
                           collections.maintainer as maintainer,
                           collections.colltype as colltype,
			   collections.url as url
                    from ports join collections on collection=collname
                    where collection = ? order by portname";

  function doQuery($repo) {
    $rows = $this->getQuery($repo);
    $ports = array();
    foreach ($rows as $row) {
      $port = new Port($row);
      array_push($ports, $port);
    }
    return $ports;
  }

  function toXML($ports) {
    return parent::toXML("ports", $ports);
  }

  function toHTML($ports) {
    $repo = $ports[0]->repo->name;
    $start = "<h2>Ports in repository $repo <a href=\"?a=getup&q={$repo}\">(get sync file)</a></h2>";
    $headers = array("Port","Collection","Files","Download command");
    return parent::toHTML($start, $headers, $ports);
  }
}

class SearchList extends PortList
{
  public $strict = false;
  public $query = '';
  public $sql;

  function __construct($dbhandle, $strict) {
    parent::__construct($dbhandle);
    if ($strict == "true") $this->strict = true;
    $sql = "select ports.portname as portname,
                   collections.collname as collname,
                   collections.maintainer as maintainer,
                   collections.colltype as colltype,
                   collections.url as url
            from ports join collections on collection=collname ";
    if ($this->strict) {
      $this->sql = $sql . "where portname=? ";
    }
    else {
      $this->sql = $sql . "where portname like ? ";
    }
    $this->sql .= "order by portname, collection";
  }

  function htmlHeader() {
    $html = parent::htmlHeader();
    $html .= '<h2>Simple port search</h2>
                <p>Search for ports by name</p>
                <form name="searchform" method="get" action="'.getenv("SCRIPT_NAME").'">
                <input name="q" value="'.$this->query.'" />
                <input type="hidden" name="a" value="search" />
                <input value="search" type="submit" /> 
               </form>';
    return $html;
  }

  function doQuery($query) {
    $this->query = $query;
    if ($query) {
      if (! $this->strict) $query = "%{$query}%";
      return parent::doQuery($query);
    }
    return array();
  }

  function toHTML($ports) {
    if ($this->query) {
      $start = ""; #<h2>Search results for '{$this->query}'</h2>";
      $headers = array("Port","Collection","Files","Download command");
      return PortDb::toHTML($start, $headers, $ports);
    }
    return $this->htmlHeader() . $this->htmlFooter();
  }
}

class DuplicateList extends PortDb
{
  public $sql = "select portname, count(*) as dup
                    from ports group by portname
                    having dup>1
                    order by dup desc";

  function doQuery() {
    $rows = $this->getQuery();
    $dups = array();
    foreach ($rows as $row) {
      $dup = new Duplicate($row);
      array_push($dups, $dup);
    }
    return $dups;
  }

  function toHTML($duplicates) {
    $start = "<h2>List of duplicate ports</h2>";
    $headers = array("Port", "# of duplicates");
    return parent::toHTML($start, $headers, $duplicates);
  }

  function toXML($duplicates) {
    return parent::toXML("duplicates",$duplicates);
  }
}

class RegisterPage
{
  function toHTML() {
    return PortDb::htmlHeader() . $this->contents() . PortDb::htmlFooter();
  }

  function contents() {
    return file_get_contents("register.html");
  }
}

class GetUp extends PortDb
{
  public $sql = "select collname,maintainer,colltype,url
                 from collections where collname=?";
  public $repo;

  function doQuery($repo) {
    $rows = $this->getQuery($repo);
    if (count($rows) != 1) die ("Could not generate file");
    return new Repo($rows[0]);
  }

  function toHTML($repo) {
    header('Content-type: text/plain');
    header('Content-Disposition: attachment; filename="'.$repo->name.".".$repo->type.'"');
    $html = "# Collection ".$repo->name. ", by ".$repo->nospam()."\n";
    $html .= "# File generated by the CRUX portdb http://crux.nu/portdb/"."\n\n";
    if ($repo->type == "httpup") {
        $html .= "ROOT_DIR=/usr/ports/" . $repo->name."\n";
        $html .= "URL=" . $repo->url."\n";
    } else {
        $ar = explode('::', $repo->url);
        $html .= "host=" . $ar[0]."\n";
        $html .= "collection=" . $ar[1]."\n";
        $html .= "destination=/usr/ports/" . $repo->name."\n";
    }
    return $html;
  }

  function toXML($repo) {
    return $this->toHTML($repo);
  }
}
    
$action = sanitize($_GET['a']);
$query = sanitize($_GET['q']);
$format = sanitize($_GET['f']);
$strict = sanitize($_GET['s']);

switch ($action) {
case "repo":
  $portdb = new PortList($dbhandle);
  break;
case "search":
  $portdb = new SearchList($dbhandle,$strict);
  break;
case "dups":
  $portdb = new DuplicateList($dbhandle);
  break;
case "getup":
  $portdb = new GetUp($dbhandle);
  break;
case "register":
  $portdb = new RegisterPage();
  echo $portdb->toHTML();
  exit;
default:
  $portdb = new RepoList($dbhandle);
}

$result = $portdb->doQuery($query);

switch ($format) {
case "xml":
  echo $portdb->toXML($result);
  break;
default:
  echo $portdb->toHTML($result);
}
?>
