#!/usr/bin/php
<?php

require("../config.php");

$start= "2013-06-01";

$con = mysqli_connect("localhost", $config["oim_db_user"], $config["oim_db_pass"], "oim");
if(mysqli_connect_errno($con)) {
    echo "failed to connect";
    echo mysqli_connect_error();
    exit;
}

$requests = array(); //catalog of all request records
$sql = "SELECT l.*, UNIX_TIMESTAMP(l.timestamp) timestamp_unix, c.name, c.primary_email, r.dn, v.name as vo_name FROM log l JOIN certificate_request_user r ON l.key = r.id LEFT JOIN contact c ON r.requester_contact_id = c.id JOIN vo v ON v.id = r.vo_id WHERE l.model = 'edu.iu.grid.oim.model.db.CertificateRequestUserModel' and timestamp > '$start' ORDER BY timestamp ASC";
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
    $contact_email = $row["primary_email"];
    $vo_name = $row["vo_name"];
    $dn = $row["dn"];

    if(strtolower($type) == "insert") {
        $requests[$key] = array(
            "requested_time"=>$timestamp,
            "requester_email"=>$contact_email,
            "dn"=>$dn,
            "vo_name"=>$vo_name,
            "approve_contact_name"=>null
        );
    } else if(strtolower($type)=="update") {
        $log = simplexml_load_string($row["xml"]);

        if(!isset($requests[$key])) {
            //print "log:$id for host request:$key haven't seen request event. mabe corrupted.. ignoring\n";
            continue;
        }

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
                    if(isset($requests[$key]["issued_time"])) {
                        //print "another issue for $key\n";
                        //dupliate
                        $prev_time = $requests[$key]["issued_time"];
                        $requests[$key.".".$prev_time] = $requests[$key];
                    }
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

mysqli_close($con);

echo "request_id,requester_email,requested_time,issued_time,dn,vo_name,ra_name\n";
foreach($requests as $key=>$request) {
    if(isset($request["issued_time"])) {
        print $key.",".$request["requester_email"].",".$request["requested_time"].",".$request["issued_time"].",".$request["dn"].",".$request["vo_name"].",".$request["approve_contact_name"]."\n";
    }
}
