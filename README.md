# ec2-backup
A PHP program for backing up running ec2 instances on aws

This script will scan the provided aws region for all your running ec2 instances, and then back up all instances according to the preferences you specify. It will delete old images based on time or the number of images that exist for a given instance, as per the preferences you specify. It also removes the snapshots that are associated with the images. It will also automatically detect and remove failed images. All you need to provide is the region, and your aws key and secret. It's great for dropping on your webhost and adding to a cron job. Make sure to put the script in the same directory as the aws.phar file: https://github.com/aws/aws-sdk-php/releases This was written with the aws phar version 3.19.32.

NOTE: This will never touch images/snapshots that are not from a instance that is running at the time of script execution. So you don't have to worry about it deleting snapshots of powered off or terminated instances -- or making snapshots of instances that haven't changed because they are off.

BE CAREFUL! This will reboot all your instances when it runs, so you need to schedule it to run appropriately

