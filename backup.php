<?php
print "<pre>";

define("AWS_ACCESS_KEY_ID", "XXXXXXXXXXXXXXXXXXXX");
define("AWS_SECRET_ACCESS_KEY", "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX");
define("AWS_REGION" , "us-east-1");

# if we already have a snapshot of any instance that is less than MIN_IMAGE_AGE seconds old, don't take another snapshot
define("MIN_IMAGE_AGE", 82800); # 82800 seconds = 23 hours

# if there are more than MAX_IMAGES images, delete the oldest ones to get down to this number
define("MAX_IMAGES", 33);

# delete images that are more than MAX_IMAGE_AGE seconds old
define("MAX_IMAGE_AGE", 2592000); # 2592000 seconds = 1 month

# even if the images are more than MAX_IMAGE_AGE seconds old, don't delete them if we will end up with less than MIN_IMAGES
define("MIN_IMAGES", 3);

require 'aws.phar';

$awsSDK = new Aws\Sdk([
	'credentials' => array(
		'key'    => AWS_ACCESS_KEY_ID,
		'secret' => AWS_SECRET_ACCESS_KEY,
	),
	'version'  => 'latest',
	'region'   => AWS_REGION
]);


$ec2Client = $awsSDK->createEc2();
$StsClient = $awsSDK->createSts();
$callerAccountID = $StsClient->getCallerIdentity([])->get('Account');

// get all the AMI's on the account
$images = $ec2Client->describeImages(['Owners' => [$callerAccountID]])->get('Images');

// look for failed ami's
$failedImages = array();
foreach ($images as $im){
	if($im['State'] == 'failed') {
		$failedImages[$im['ImageId']] = null;
	}
}

// deregister failed ami's
if (!empty($failedImages)){
	print "the following images have failed and will be deregistered: \n";
	print_r($failedImages);
	print "\n\n";
	foreach ($failedImages as $im => $val){
		$ec2Client->deregisterImage(['ImageId' => $im]);
	}
}

// look for unavailable AMI's -- this includes failed and pending. Abort script if there are any
foreach ($images as $im){
	if($im['State'] != 'available') {
		print "the script is aborting because there are AMI's in pending or failed status </pre>";
		die();
	}
}

// array reservations containing running instances (they are inside of "reservation" arrays)
$reservations =	$ec2Client->describeInstances([
					'Filters' => [
						[
							'Name' => 'instance-state-name',
							'Values' => ['running']
						]
					]
				])->get('Reservations');

// flatten out $reservations into an array of running instances
$instances = array();
foreach ($reservations as $r) {
	foreach ($r['Instances'] as $i) {
		$instances[] = $i;
	}
}

// get all volume snapshots on the account
$snapshots = $ec2Client->describeSnapshots(['OwnerIds' => [$callerAccountID]])->get('Snapshots');

// find which images belong to which instance
$instanceImageTimeMap = array();
foreach ($images as $im){
	// imageId:  $im['ImageId']  is from snapshotId:  $im['BlockDeviceMappings'][0]['Ebs']['SnapshotId']
	foreach ($snapshots as $sn){
		if ($sn['SnapshotId'] == $im['BlockDeviceMappings'][0]['Ebs']['SnapshotId']){
			// snapshotId:  $im['BlockDeviceMappings'][0]['Ebs']['SnapshotId'] is from VolumeId:  $sn['VolumeId']
			foreach ($instances as $in){
				if ($in['BlockDeviceMappings'][0]['Ebs']['VolumeId'] == $sn['VolumeId']) {
					// VolumeId: $sn['VolumeId'] is from InstanceId: $in['InstanceId']
					// imageId: $im['ImageId'] is from InstanceId: $in['InstanceId']
					$instanceImageTimeMap[$in['InstanceId']][$im['ImageId']] = $im['CreationDate'];
				}
			}
		}
	}
}
foreach ($instanceImageTimeMap as $key => $item){
	asort($instanceImageTimeMap[$key]);
}
print "Here are the existing images corresponding to each running instance: \n";
print_r($instanceImageTimeMap);
print "\n\n";

// build an array of instances that haven't been imaged since (NOW minus MIN_IMAGE_AGE)
$instancesToImage = array();
foreach ($instanceImageTimeMap as $instance => $imageIds) {
	foreach ($imageIds as $imageId => $imageTime) {
		if ((time() - strtotime($imageTime)) < MIN_IMAGE_AGE) {
			print "not imaging " . $instance . " due to MIN_IMAGE_AGE \n";
			break;
		} else {
			$instancesToImage[$instance] = null;
		}
	}
}

$imagesToDeregister = array();

// delete images that are older than MAX_IMAGE_AGE seconds
foreach ($instanceImageTimeMap as $in) {
	foreach ($in as $imageId => $imageTime) {
		if ((time() - strtotime($imageTime)) > MAX_IMAGE_AGE) {
			if ((count($in) - count($imagesToDeregister)) > MIN_IMAGES) {
				print "deleting ImageId: " . $imageId . " due to MAX_IMAGE_AGE \n";
				$imagesToDeregister[$imageId] = null;
			} else {
				print "Skipped deleting image: " . $imageId . " (which exceeds MAX_IMAGE_AGE) due to MIN_IMAGES \n";
			}
		}
	}
}

// delete oldest images that causes a MAX_IMAGES violation
foreach ($instanceImageTimeMap as $in) {
	if (count($in) > MAX_IMAGES) {
		// deleting (count($in) - MAX_IMAGES) image(s) (number of images to delete)
		for ($x = 0; $x < (count($in) - MAX_IMAGES); $x++) {
			print "deleting ImageId: " . array_keys($in)[$x] . " due to MAX_IMAGES \n";
			$imagesToDeregister[array_keys($in)[$x]] = null;
		}
	}
}

$snapshotsToDelete = array();
foreach ($images as $im) {
	if (array_key_exists($im['ImageId'], $imagesToDeregister)){
		foreach($im['BlockDeviceMappings'] as $bl) {
			$snapshotsToDelete[$bl['Ebs']['SnapshotId']] = null;
		}		
	}
}

// get all volumes on the account
$volumes = $ec2Client->DescribeVolumes();

print "\n\nHere are the instances we are going to image: \n";
print_r($instancesToImage);
print "\n\n";

print "Here are the images we are going to deregister: \n";
print_r($imagesToDeregister);
print "\n\n";

print "Here are the snapshots we are going to delete: \n";
print_r($snapshotsToDelete);

foreach ($instancesToImage as $in => $val){
	$imageName = $in . " @ " . date("Y-m-d H.i.s");
	$ec2Client->createImage(['InstanceId' => $in, 'Name' => $imageName, 'NoReboot' => false]);
}

foreach ($imagesToDeregister as $im => $val){
	$ec2Client->deregisterImage(['ImageId' => $im]);
}

foreach ($snapshotsToDelete as $sn => $val){
	$ec2Client->deleteSnapshot(['SnapshotId' => $sn]);
}


print "</pre>";
?>
