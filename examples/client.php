<?php

// Set to E_ALL for testing, but you should set it as appropriate for your system.
// See http://www.php.net/manual/en/errorfunc.constants.php for a listing of possible error level settings.
// See http://php.net/manual/en/function.error-reporting.php for how to use them.
error_reporting(E_ALL);

// This must be set to wherever you have stored the KnowledgeTree PHP client library.
$root = realpath('../');
require_once "$root/KTClient.inc.php";

$username = 'example@knowledgetree.com';
$password = 'example';
// NOTE http is not recommended unless you do not have SSL enabled on your server.
$url = 'https://example.knowledgetree.com';

try {
    $client = new KTClient($url);

    // For a first time login for this user.
    $sessionId = $client->initSession($username, $password);

    /**
     * You can also get the session id using
     * $sessionId = $client->getSessionId();
     *
     * You can re-use a session by calling
     * $client->reuseSession($sessionId);
     */

    // Add a folder.
    $folderId = $client->addFolder('An API Test Folder');

    // Add a document.
    $documentId = $client->addDocument('36243.pdf', 'E:\S3\36243.pdf', 1, 'test file');

    // Remove a document (in this case the one we just added.)
    $client->removeDocument($documentId);

    // Simple Search.
    $hits = $client->search('original');
    echo '<pre>' . print_r($hits, true) . '</pre>';

    // Advanced Search.
    $hits = $client->advancedSearch('(Title contains "original")');
    echo '<pre>' . print_r($hits, true) . '</pre>';

    // Locate a folder (get the folder id) by supplying the full path to the folder.
    $folderId = $client->locateFolderByPath('/examplefolder/subfolder');
    print "Located folder at ID $folderId<br/>";

    // Browse the folder tree from a specified base folder.
    $tree = $client->browse(1);
    echo '<pre>' . print_r($tree, true) . '</pre>';

    // Check out a document (without download.)
    $client->checkOut(2);

    // Check out a document (with download.)
    $client->checkOutWithDownload(13);

    // Check in a document.
    $client->checkIn(13, 'testfile', 'C:\path\to\testfile');

    // Download a document (without checking it out.)
    $client->downloadDocument(13);

    // Get metadata for a document.
    $metadata = $client->getDocumentMetadata(13);
    echo '<pre>' . print_r($metadata, true) . '</pre>';

    // Set metadata for a document.
    $metadata['Tag Cloud']['Tag']['value'][] = 'a soap tag';
    $metadata['General information']['Document Author']['value'] = 'A SOAP Based author submission';
    $metadata['General information']['Category']['value'] = 'Administrative';
    //$metadata['General information']['Media Type']['value'] = 'An invalid selection which should cause a failure';
    $client->setDocumentMetadata(13, $metadata);

    // Add a comment to a document.
    $client->addComment(18, 'This is a test comment from the KnowledgeTree PHP SOAP client');

    // Get all comments for a document.
    $comments = $client->getComments(18);
    echo '<pre>' . print_r($comments, true) . '</pre>';

    // Get the permissions on a folder - first get the folder id
    $folderId = $client->locateFolderByPath('/examplefolder/subfolder');

    $permissions = $client->getFolderPermissions($folderId);
    echo '<pre>' . print_r($permissions, true) . '</pre>';
    
    // If the folder permissions are inherited then override them and set new permissions
    if ($permissions['isInherited']) {
        
        // Create the permissions array
        // The listGroups function can be used to get the correct groups
        $group1['id'] = 1; // The "System Administrators" group
        $group1['allocated_permissions'] = Array(
                            'read' => 'true',
                            'security' => 'true',
                            'folder_details' => 'true'
                        );
        $group2['id'] = 5; // Another group in the system
        $group2['allocated_permissions'] = Array (
                            'read' => 'true',
                            'write' => 'true',
                            'workflow' => 'true',
                            'folder_details' => 'true'
                        );
        
        // The listRoles function can be used to get the correct roles
        $role1['id'] = -4; // The "Everyone" role in the system
        $role1['allocated_permissions'] = Array (
                            'read' => 'true',
                            'folder_details' => 'true'
                        );
        
        $newPermissions['groups'][] = $group1;
        $newPermissions['groups'][] = $group2;
        $newPermissions['roles'][] = $role1;
        
        // Update the permissions
        $message = $client->setFolderPermissions($folderId, $newPermissions);
        print "Permissions Update response: $message<br/>";
    }
    else {
        // If the folder defines its own permissions, then we update it to inherit from the parent folder
        $message = $client->inheritParentFolderPermissions($folderId);
        print "Permissions Inherit response: $message<br/>";
    }

    $client->closeSession();
}
catch (Exception $e) {
    print $e->getMessage();
}

?>
