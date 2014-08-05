#!/usr/bin/php
<?php
require("../config.php");

$start = "2013-06-01";

$con = mysqli_connect("localhost", $config["oim_db_user"], $config["oim_db_pass"], "oim");
if(mysqli_connect_errno($con)) {
    echo "failed to connect";
    echo mysqli_connect_error();
    exit;
}

$requests = array(); //catalog of all request records
//$sql = "SELECT * from log WHERE model = 'edu.iu.grid.oim.model.db.CertificateRequestHostModel' AND `key` = '749' ORDER BY timestamp";
$sql = "SELECT l.*, UNIX_TIMESTAMP(l.timestamp) timestamp_unix, c.name, r.cns, v.name as voname FROM log l LEFT JOIN contact c ON l.contact_id = c.id JOIN certificate_request_host r ON l.key = r.id JOIN vo v on v.id = r.approver_vo_id WHERE l.model = 'edu.iu.grid.oim.model.db.CertificateRequestHostModel' and timestamp > '$start' ORDER BY timestamp ASC";
$result = mysqli_query($con,$sql);
while($row = mysqli_fetch_array($result)) {
    $id = (int)$row["id"]; //logid
    $key = (int)$row["key"];
    $type = $row["type"]; //insert/update/remove
    $dn_id = (int)$row["dn_id"];
    $contact_id = (int)$row["contact_id"];
    //$timestamp = (int)$row["timestamp_unix"];
    $timestamp = $row["timestamp"];
    $contact_name = $row["name"];
    $vo_name = $row["voname"];

    if($type == "insert") {
        /*
        foreach($log->Fields->Field as $f) {
            $name = (string)$f->Name[0];
            $value = (string)$f->Value[0];
            switch($name) {
                     case "cns":
                $xml = html_entity_decode($value);
                $doc = simplexml_load_string($xml);
                foreach($doc->String as $s) {
                    $cns[] = (string)$s;
                }
                break;
            }
        }
        */
        $doc = simplexml_load_string($row["cns"]); 
        $cns = array();
        foreach($doc->String as $s) {
            $cns[] = (string)$s;
        }
        
        $requests[$key] = array(
            "requested_time"=>$timestamp,
            "cns"=>$cns,
            "request_contact_id"=>$contact_id,
            "request_contact_name"=>$contact_name,
            "approve_contact_name"=>null,
            "voname"=>$vo_name
        );
    } else if($type=="update") {
        $log = simplexml_load_string($row["xml"]);

        if(!isset($requests[$key])) {
            //print "log:$id for host request:$key haven't seen request event. mabe corrupted.. ignoring\n";
            continue;
        }

        /*
        $requests[$key] = array(
            "requested_time"=>$timestamp,
            "cns"=>$cns,
            "contact_id"=>$contact_id,
            "contact_name"=>$contact_name
        );
        */

        foreach($log->Fields->Field as $f) {
            $name = (string)$f->Name[0];
            switch($name) {
            case "status":
                $old = (string)$f->OldValue[0];
                $new = (string)$f->NewValue[0];
                switch($new) {
                case "APPROVED":
                    $requests[$key]["approved_time"]=$timestamp;
                    $requests[$key]["approve_contact_name"]=$contact_name;
                    $requests[$key]["approve_contact_id"]=$contact_id;
                    break;
                case "ISSUED":
                    $requests[$key]["issued_time"]=$timestamp;
                    break;
                default:
                    //print "unknown $new\n";
                }
                break;
            }
        }
    }
}

//explode cns into separate records
$requests_e = array();
foreach($requests as $key=>$request) {
    if(isset($request["issued_time"])) {
        foreach($request["cns"] as $cn) {
            $cn_parts = explode(".", $cn);
            $domain_parts = array_splice($cn_parts, -2);
            $domain = implode(".",$domain_parts);
            $requests_e[] = array(
                "id"=>$key,
                "requested_time"=>$request["requested_time"], 
                "issued_time"=>$request["issued_time"],
                "cn"=>$cn,
                "domain"=>$domain,
                "requester"=>$request["request_contact_name"],
                "approve_contact_name"=>$request["approve_contact_name"],
                "voname"=>$request["voname"]
            );
        }
    }
}
//load grid admin table
$sql = "select grid_admin.domain, contact.name as ga, vo.name as voname from grid_admin join contact on contact.id = contact_id join vo on vo.id = vo_id;";
$result = mysqli_query($con,$sql);
$grecs = array();
while($row = mysqli_fetch_array($result)) {
    $grecs[] = $row;
}

function endsWith($haystack, $needle)
{
    //print "searchin $haystack with needle:$needle\n";
    return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
}

/*
//testing endsWith
if(endsWith("m3.quarry.teragrid.iu.edu", "grid.iu.edu")) {
    print "shouldn't match";
}
*/

//override selected vos based on fqdn / ga and output 
echo "request_id,requested_time,issued_time,cn,domain,requester_name,ga_name,vo_name,vo_name_derived\n";
foreach($requests_e as $request) {
    //debug
    //if($key != 448) continue; 
    //var_dump($request);

    //find best matching GA
    $match_len = 0;
    $bestvo = null;
    foreach($grecs as $grec) {
        $cnlen = strlen($request["cn"]);
        $dlen = strlen($grec["domain"]);
        //prefix . before searching domain to prevent teragrid.iu.edu matching grid.iu.edu
        if($cnlen > $dlen) {
            $grec["domain"] = ".".$grec["domain"];
        }
        //print $request["cn"]." and ".$grec["domain"]."\n";
        //print $grec["ga"]." and ".$request["approve_contact_name"]."\n";
        if($request["approve_contact_name"] == $grec["ga"] && endsWith($request["cn"], $grec["domain"])) {
            //var_dump($grec);
            if($match_len < $dlen) {
                //if($match_len != 0) { print "better match found: ".$gred["domain"];}
                $match_len = $dlen;
                $bestvo = $grec["voname"];
            }
            //print "id:$key has ".$request["cn"]." and ".$request["approve_contact_name"]." which should have ".$grec["voname"]." instead of ".$request["voname"]."\n";
        }
    }
    if(is_null($bestvo)) { 
        //no gridadmin assigned for this CN / GA combo (just use what is stored in DB)
        $bestvo = $request["voname"];
    }
    print $request["id"].",".
        $request["requested_time"].",".
        $request["issued_time"].",".
        $request["cn"].",".
        $request["domain"].",".
        $request["requester"].",".
        $request["approve_contact_name"].",".
        $request["voname"].",".
        $bestvo."\n";
}

mysqli_close($con);

