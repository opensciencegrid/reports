#!/bin/bash

email=hayashis@iu.edu
start=$(date +"%Y-%m-%d" --date="6 month ago")
end=$(date +"%Y-%m-%d")
#echo $start "-- report start date $start"
php pkireports/user.php $start | uuencode /tmp/pkireports.$start-to-$end.user.csv | mail -s "pki reports user certificate" $email
php pkireports/host.php $start | uuencode /tmp/pkireports.$start-to-$end.host.csv | mail -s "pki reports host certificate" $email

