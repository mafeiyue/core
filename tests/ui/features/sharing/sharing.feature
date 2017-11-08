@insulated
Feature: Sharing files and folders with internal users
As a user
I want to share files and folders with groups and users
So that those groups and users can access the files and folders

	Background:
		Given these users exist:
		|username|password|displayname|email       |
		|user1   |1234    |User One   |u1@oc.com.np|
		|user2   |1234    |User Two   |u2@oc.com.np|
		|user3   |1234    |User Three |u2@oc.com.np|
		And these groups exist:
		|groupname|
		|grp1     |
		And the user "user1" is in the group "grp1"
		And the user "user2" is in the group "grp1"
		And I am on the login page

	Scenario: share a file & folder with another internal user
		And I login with username "user2" and password "1234"
		When the folder "simple-folder" is shared with the user "User One"
		And the file "testimage.jpg" is shared with the user "User One"
		And I logout
		And I login with username "user1" and password "1234"
		Then the folder "simple-folder (2)" should be listed
		And the folder "simple-folder (2)" should be marked as shared by "User Two"
		And the file "testimage (2).jpg" should be listed
		And the file "testimage (2).jpg" should be marked as shared by "User Two"
		And I open the folder "simple-folder (2)"
		Then the file "lorem.txt" should be listed
		But the folder "simple-folder (2)" should not be listed

	Scenario: share a folder with an internal group
		And I login with username "user3" and password "1234"
		When the folder "simple-folder" is shared with the group "grp1"
		And the file "testimage.jpg" is shared with the group "grp1"
		And I logout
		And I login with username "user1" and password "1234"
		Then the folder "simple-folder (2)" should be listed
		And the folder "simple-folder (2)" should be marked as shared with "grp1" by "User Three"
		And the file "testimage (2).jpg" should be listed
		And the file "testimage (2).jpg" should be marked as shared with "grp1" by "User Three"
		And I logout
		And I login with username "user2" and password "1234"
		Then the folder "simple-folder (2)" should be listed
		And the folder "simple-folder (2)" should be marked as shared with "grp1" by "User Three"
		And the file "testimage (2).jpg" should be listed
		And the file "testimage (2).jpg" should be marked as shared with "grp1" by "User Three"

	@skipOnMICROSOFTEDGE
	Scenario: share a folder with another internal user and prohibit deleting
		And I login with username "user2" and password "1234"
		When the folder "simple-folder" is shared with the user "User One"
		And the sharing permissions of "User One" for "simple-folder" are set to
		| delete | no |
		And I logout
		And I login with username "user1" and password "1234"
		And I open the folder "simple-folder (2)"
		Then it should not be possible to delete the file "lorem.txt"