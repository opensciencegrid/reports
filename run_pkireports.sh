
#TODO - I need to pass start/end date

start=$(date +"%Y-%m-%d" --date="6 month ago")
echo "report start date $start"
php pkireports/user.php $start
php pkireports/host.php $start
