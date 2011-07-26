<?php

/**
 * This class handles connections and requests to the KnowledgeTree SOAP webservice.
 *
 * The following actions are supported:
 *
 *  Add a document to the repository.
 *  Remove (delete) a document from the repository.
 *  Search for documents by name, id, or content (NOTE Check that content search is enabled via webservices.)
 *  Locate a folder by the folder path (returns the folder ID.)
 *  Browse the folder tree (from a relative parent folder.)
 *  Check out a document (with optional download.)
 *  Check in a document (requires upload of a new version.)
 *  Download a document.
 *  Get document metadata.
 *  Set document metadata.
 *  Add a comment to a document.
 *  Get all comments for a document.
 *
 *  On errors with any request an exception will be thrown.
 */

class KTWebserviceException extends Exception {}

class KTClient {

    const FIELDSET_DEFAULT_VALUE = 'n/a';

    private $uploadUrl;
    private $webserviceUrl;
    private $apiVersion = 3;
    private $application = 'KTPHPClient';
    private $ip;
    private $sessionId;
    private $cacheWsdl = WSDL_CACHE_DISK;

    /**
     * Construct a new KnowledgeTree client instance.
     *
     * @param string $server The url of the KnowledgeTree server associated with this instance.
     * @param array $options [Optional] Additional inputs.
     *
     * Optional inputs can be:
     *
     * 1. 'ip': IP address from which you are using the client.  If not supplied this is set to a random string.
     * 2. 'application': Application name to differentiate different KnowledgeTree client applications
     *                   which may wish to access the KnowledgeTree instance at the same time.
     *                   This associates the session with the particular app.
     * 3. 'cacheWsdl': Controls whether to cache the KnowledgeTree wsdl document.
     *                 Choosing not to cache will mean more bandwidth on repeated connections.
     *                 Choosing to cache will mean that if changes are made you will need to force a refresh.
     *                 Changes to the webservice should not occur often, and generally will involve addition of fields,
     *                 so caching on is recommended.
     *
     *                 Available options are WSDL_CACHE_NONE, WSDL_CACHE_DISK, WSDL_CACHE_MEMORY or WSDL_CACHE_BOTH.
     *                 Default is WSDL_CACHE_DISK.
     */
    public function __construct($server, Array $options = array())
    {
        $server = trim($server, '/');
        $this->webserviceUrl = "{$server}/ktwebservice/webservice.php?";
        $this->uploadUrl = "{$server}/ktwebservice/upload.php";

        $this->ip = empty($options['ip']) ? sha1(mt_rand()) : $options['ip'];

        empty($options['application']) or $this->application = $options['application'];
        !isset($options['cacheWsdl']) or $this->cacheWsdl = $options['cacheWsdl'];
    }

    /**
     * Reuse an existing session instead of instantiating a new one.
     * If the existing session turns out to have expired then you will have to start a new one.
     *
     * @param string $sessionId The id of an existing session.
     */
    public function reuseSession($sessionId)
    {
        $this->sessionId = $sessionId;
    }

    private function executeRequest($request, $parameters = array())
    {
        if (!empty($this->sessionId)) {
            array_unshift($parameters, $this->sessionId);
        }

        $result = $this->client->__soapCall($request, $parameters);
        if ($this->requestSuccessful($result)) {
            return $result;
        }

        throw new KTWebserviceException($result->message);
    }

    private function requestSuccessful($result)
    {
        return $result->status_code === 0;
    }

    // NOTE Recursive calls are made for elements of both array and object type.
    //      This is to ensure that any objects hidden within sub-arrays are converted.
    private function convertToArray($input)
    {
        $output = array();

        foreach ($input as $key => $value) {
            $output[$key] = (is_array($value) || is_object($value)) ? $this->convertToArray($value) : $value;
        }

        return $output;
    }

    /**
     * Create a new KnowledgeTree client session and sets the session id to be used for subsequent requests.
     * Auto-connects if there is no existing connection.
     *
     * @param string $username The KnowledgeTree username for this session.
     * @param string $password The KnowledgeTree password for this user.
     *
     * @return string The id for the newly created session.
     */
    public function initSession($username, $password)
    {
        if (empty($this->client) && !$this->connect()) {
            throw new KTWebserviceException('Unable to connect to the KnowledgeTree SOAP webservice');
        }

        $parameters = array($username, $password, $this->ip, $this->application);
        $result = $this->executeRequest('login', $parameters);
        $this->sessionId = $result->message;

        return $this->sessionId;
    }

    private function connect()
    {
        try {
            $wsdl = $this->webserviceUrl . 'wsdl';
            $this->client = new SoapClient($wsdl, array('cache_wsdl' => $this->cacheWsdl));
            return true;
        }
        catch (Exception $e) {
            return false;
        }
    }

    /**
     * Terminates an existing session and clears the instance session id.
     * Once this function is called the previous session id will no longer work, even if you call reuseSession.
     */
    public function closeSession()
    {
        $this->executeRequest('logout');
        $this->sessionId = null;
    }

    /**
     * Add a folder to the KnowledgeTree repository.
     *
     * @param string $name The name for the new folder.
     * @param int | string $folder [Optional] The folder into which to add the new folder.
     *                                        This can be an id or a folder path.
     *                                        If not set, defaults to the root folder.
     *                                        If you use the folder path option and specify a path which does not
     *                                        exist, an error will be raised.
     *
     * @return int The id of the newly created folder.
     */
    public function addFolder($name, $folder = 1)
    {
        $folderId = is_int($folder) ? $folder : $this->locateFolderByPath($folder);

        $parameters = array($folderId, $name);
        $result = $this->executeRequest('create_folder', $parameters);

        return $result->id;
    }

    /**
     * Add a document to the KnowledgeTree repository.
     * First executes a file upload and then a request to add the uploaded document.
     *
     * NOTE The current version of this library does not allow specifying a document type.
     *      All uploaded documents will use the default document type for the KnowledgeTree repository.
     *
     * @param string $filename The name with which this document will be stored.
     * @param string $localFilepath The (absolute) file path where the document resides on your local system.
     * @param int | string $folder [Optional] The folder into which to add the new folder.
     *                                        This can be an id or a folder path.
     *                                        If not set, defaults to the root folder.
     *                                        If you use the folder path option and specify a path which does not
     *                                        exist, an error will be raised.
     * @param string $title [Optional] The title with which the document will be saved.
     *                                 If not set, the filename is used.
     * @param string $documentType The type of document.  This must match a document type registered with the
     *                             KnowledgeTree instance, else the default type will be used.
     *
     * @return int The id of the added document.
     */
    public function addDocument($filename, $localFilepath, $folder = 1, $title = null, $documentType = 'Default')
    {
        $folderId = is_int($folder) ? $folder : $this->locateFolderByPath($folder);

        $uploadedTo = $this->uploadDocument($localFilepath);

        $title or $title = $filename;

        $parameters = array($folderId, $title, $filename, $documentType, $uploadedTo);
        $result = $this->executeRequest('add_document', $parameters);

        return $result->document_id;
    }

    private function uploadDocument($localFilepath)
    {
        if (!function_exists('curl_init')) {
            throw new KTWebserviceException('Curl support is required to upload documents');
        }

        $post = $this->setPostContent($localFilepath);
        $ch = $this->initCurlHandle($post);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        curl_close($ch);

        if ($response && strpos($info['http_code'], '2') === 0) {
            $response = json_decode($response, true);
            if ($response['status_code'] === 0 && $response['upload_status']['upload']['error'] === 0) {
                $uploadedTo = $response['upload_status']['upload']['tmp_name'];
                return $uploadedTo;
            }
        }

        throw new KTWebserviceException("An error occurred while attempting to upload the file");
    }

    private function setPostContent($localFilepath)
    {
        $post['output'] = 'json';
        $post['session_id'] = $this->sessionId;
        $post['apptype'] = $this->application;
        $post['action'] = 'A';
        $post['upload'] = "@$localFilepath";
        $post['submit'] = 'submit';

        return $post;
    }

    private function initCurlHandle($post)
    {
        $ch = curl_init($this->uploadUrl);

        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        return $ch;
    }

    /**
     * Remove a document from the KnowledgeTree repository.
     *
     * NOTE While this marks the document as deleted, it is not expunged and can be
     *      recovered through the KnowledgeTree website admin interface.
     *
     * @param int $documentId The id of the document to be removed.
     * @param string [Optional] A reason describing why the document is being removed.
     *
     * NOTE Depending on the configuration of the KnowledgeTree server the administrator may have set
     *      a requirement for submitting reasons.  In this case an error will be returned.
     */
    public function removeDocument($documentId, $reason = null)
    {
        $parameters = array($documentId, $reason);
        $this->executeRequest('delete_document', $parameters);
    }

    /**
     * Run a search on the KnowledgeTree repository.
     *
     * This performs the search: (GeneralText contains "your search terms")
     * This is the equivalent of the general search in the KnowledgeTree web interface.
     *
     * @param string $searchTerms The search terms to be used.
     *
     * @return array A listing of results found.
     */
    public function search($searchTerms)
    {
        $searchQuery = "(GeneralText contains \"$searchTerms\")";
        return $this->advancedSearch($searchQuery);
    }

    /**
     * Run an advanced search on the KnowledgeTree repository.
     * This is the equivalent of running an advanced search in the KnowledgeTree web interface,
     * except that you have to build the queries yourself.
     *
     * Search Grammar:
     *  Criteria may be built up using the following grammar:
     *  expr ::= expr { AND | OR } expr
     *  expr ::= NOT expr
     *  expr ::= (expr)
     *  expr ::= field { < | <= | = | > | >= | CONTAINS | STARTS WITH | ENDS WITH } value
     *  expr ::= field BETWEEN value AND value
     *  expr ::= field DOES [ NOT ] CONTAIN value
     *  expr ::= field IS [ NOT ] LIKE value
     *  value ::= "search text here"
     *
     * Search Fields:
     *  The following fields may be used in search criteria:
     *  CheckedOut, CheckedOutBy, CheckedoutDelta, Created, CreatedBy, CreatedDelta, DiscussionText, DocumentId,
     *  DocumentText, DocumentType, Filename, Filesize, FullPath, GeneralText, IntegrationId, IsArchived,
     *  IsCheckedOut, IsDeleted, IsImmutable, Metadata, MimeType, Modified, ModifiedBy, ModifiedDelta,
     *  OwnedBy, ParentFolder, Tag, Title, Workflow, WorkflowID, WorkflowState, WorkflowStateID
     *
     * Example search: (Title contains "original" OR DocumentText contains "text of interest")
     *
     * Parentheses are required.  Multiple sets of criteria can be grouped by parentheses.
     *
     * @param string $searchQuery The search query to be executed.
     *
     * @return array A listing of results found.
     */
    public function advancedSearch($searchQuery)
    {
        $options = null;

        $parameters = array($searchQuery, $options);
        $result = $this->executeRequest('search', $parameters);

        return $this->convertToArray($result->hits);
    }

    /**
     * Find a folder id by supplying the path to the folder.
     *
     * Folders are to be specified relative to the root folder.
     * The root folder is specified by '/' at the start of the path.
     * I.e., if you are looking for a folder named folderB, which is in folderA,
     * which is in the root folder, you will specify this as /folderA/folderB
     *
     * @param string $folderPath The full path to the folder for which you want an id.
     *
     * @return int The id of the folder.
     */
    public function locateFolderByPath($folderPath)
    {
        $parameters = array($folderPath);
        $result = $this->executeRequest('locate_folder_by_path', $parameters);

        return $result->folder_id;
    }

    /**
     * Browse the KnowledgeTree repository.
     * Browsing is relative to the supplied root folder id.
     *
     * @param int | string $baseFolder [Optional] The id or name of the folder from which to start the listing.
     *                                            This can be an id or a folder path.
     *                                            If not set, defaults to the root folder.
     *                                            If you use the folder path option and specify a path which does not
     *                                            exist, an error will be raised.
     * @param int $depth [Optional] How deeply to traverse the tree.  -1 means all the way down.
     *                              Any positive number will return that many levels.
     * @param string $type [Optional] The types of items to be returned in the listing.
     *
     * Types can be specified as 'D' = Documents, 'F' = Folders, 'S' = Shortcuts, or a combination.
     * The default will return all three.
     *
     * @return array A listing of items found.
     */
    public function browse($baseFolder = 1, $depth = -1, $type = 'DFS')
    {
        $folderId = is_int($baseFolder) ? $baseFolder : $this->locateFolderByPath($baseFolder);

        $parameters = array($folderId, $depth, $type);
        $result = $this->executeRequest('get_folder_contents', $parameters);

        return $this->convertToArray($result->items);
    }

    /**
     * Check out a document from the KnowledgeTree repository.
     *
     * @param int $documentId The id of the document to be checked out.
     * @param string [Optional] A reason describing why the document is being checked out.
     *
     * NOTE Depending on the configuration of the KnowledgeTree server the administrator may have set
     *      a requirement for submitting reasons.  In this case an error will be returned.
     *
     * If you want to download the document when checking it out, then use the checkOutWithDownload function.
     * Alternately you can issue a download request after checking out.
     */
    public function checkOut($documentId, $reason = null)
    {
        // NOTE The last parameter specifies whether to download the document
        //      in addition to checking it out.  Default is true.
        $parameters = array($documentId, $reason, false);
        $this->executeRequest('checkout_document', $parameters);
    }

    /**
     * Check out a document from the KnowledgeTree repository and download it.
     *
     * @param int $documentId The id of the document to be checked out.
     * @param string [Optional] A reason describing why the document is being checked out.
     *
     * NOTE Depending on the configuration of the KnowledgeTree server the administrator may have set
     *      a requirement for submitting reasons.  In this case an error will be returned.
     *
     * NOTE A download will issue a header redirect.
     *      Therefore you cannot send any output to a web page prior to issuing the download request.
     *
     *      The download also exits the current script and no more code will be processed.
     */
    public function checkOutWithDownload($documentId, $reason = null)
    {
        if ($this->checkOut($documentId, $reason)) {
            $this->downloadDocument($documentId);
        }
    }

    /**
     * Download a document from the KnowledgeTree repository.
     *
     * @param int $documentId The id of the document to be downloaded
     * @param string [Optional] Version number to be downloaded.  E.g., 0.3, 1.6, etc.
     *
     * NOTE A download will issue a header redirect.
     *      Therefore you cannot send any output to a web page prior to issuing the download request.
     *
     *      The download also exits the current script and no more code will be processed.
     */
    public function downloadDocument($documentId, $version = null)
    {
        $parameters = array($documentId, $version);
        $this->executeRequest('download_document', $parameters);

        $downloadRedirect = "Location: {$result->message}";
        header($downloadRedirect);
        exit(0);
    }

    /**
     * Check in a checked out document.
     *
     * @param int $documentId The id of the document you are checking in.
     * @param string $filename The name with which this document will be stored.
     * @param string $localFilepath The (absolute) file path where the document resides on your local system.
     * @param boolean $majorUpdate [Optional] Whether this check in should result in a minor or major version bump.
     *                             A minor version would be, e.g., 0.1 to 0.2, major would be, e.g., 0.6 to 1.0.
     * @param string [Optional] A reason describing why the document is being checked in.
     *
     * NOTE Depending on the configuration of the KnowledgeTree server the administrator may have set
     *      a requirement for submitting reasons.  In this case an error will be returned.
     */
    public function checkIn($documentId, $filename, $localFilepath, $majorUpdate = false, $reason = null)
    {
        $uploadedTo = $this->uploadDocument($localFilepath);

        $parameters = array($documentId, $filename, $reason, $uploadedTo, $majorUpdate);
        $this->executeRequest('checkin_document', $parameters);
    }

    /**
     * Retrieve metadata associated with a document.
     *
     * @param int $documentId The id of the document for which you are requesting metadata.
     *
     * @return array A listing of the current metadata, which can be edited and resubmitted for update.
     *
     * Metadata is (generally speaking) structured as follows:
     *
     * Array (
     *      'Fieldset Name' => Array (
     *                              'Field Name' => Array (
     *                                              'Value' => 'Value',
     *                                              'required' => true | false,
     *                                              'options' => Array()
     *                                         ),
     *                              ...
     *                         )
     * )
     *
     * If a field can hold multiple values, then the 'Value' element will be an integer indexed array.
     * You can add and remove values by adding and removing elements from the 'Value' array.
     *
     * The options field will only be present when a metadata item is restricted to certain values.
     * If you submit a value other than one specified in the options array, an error will be raised.
     */
    public function getDocumentMetadata($documentId)
    {
        $parameters = array($documentId);
        $result = $this->executeRequest('get_document_metadata', $parameters);

        $metadata = array();
        foreach ($result->metadata as $fieldset) {
            $metadata[$fieldset->fieldset] = $this->formatMetadataResultFields($fieldset->fields);
        }

        return $metadata;
    }

    private function formatMetadataResultFields($fields)
    {
        $output = array();

        foreach ($fields as $field) {
            if (is_array($field)) {
                $name = $field['name'];
                $data = $this->extractMetadataFieldFromArray($field);
            }
            else {
                $name = $field->name;
                $data = $this->extractMetadataFieldFromClass($field);
            }

            if ('Tag' == $name) {
                $data['value'] = explode(',', $data['value']);
            }

            $output[$name] = $data;
        }

        return $output;
    }

    private function extractMetadataFieldFromArray($field)
    {
        $fieldData = array();

        $fieldData['value'] = ($field['value'] == 'n/a' ? null : $field['value']);
        $fieldData['required'] = $field['required'];
        empty($field['selection']) or $fieldData['options'] = $this->extractSelectionOptions($field['selection']);

        return $fieldData;
    }

    private function extractMetadataFieldFromClass($field)
    {
        $fieldData = array();

        $fieldData['value'] = ($field->value == 'n/a' ? null : $field->value);
        $fieldData['required'] = $field->required;
        empty($field->selection) or $fieldData['options'] = $this->extractSelectionOptions($field->selection);

        return $fieldData;
    }

    private function extractSelectionOptions($selection)
    {
        $options = array();

        foreach ($selection as $option) {
            $options[] = $option->value;
        }

        return $options;
    }

    /**
     * Set the metadata for an existing document.
     * This can be used for setting metadata for a newly uploaded document or one uploaded previously.
     *
     * Note, if you have conditional metadata, you must first get the existing metadata.
     * If you do not do this, conditional metadata will be unset on submission.
     *
     * Best practice is to just always get the current metadata before attempting to set,
     * unless you have just uploaded the document for the first time.
     *
     * @param int $documentId The id of the document for which to set the metadata.
     * @param array The metadata which is to be set.
     *
     * Metadata input requires the following format:
     *
     * Array (
     *      'Fieldset Name' => Array (
     *                              'Field Name' => Array (
     *                                              'Value' => 'Value'
     *                                         ),
     *                              ...
     *                         )
     * )
     *
     * If you are using metadata retrieved from KnowledgeTree with the additional 'required' and/or 'options'
     * fields, you do not need to remove these, they will just be ignored.
     *
     * NOTE You cannot create new metadata fieldsets through this function, you can only set metadata
     *      for fieldsets which already exist in the KnowledgeTree repository.
     *
     * TODO Consider a function to retrieve a listing of available metadata fieldsets and fields?
     *      I think this assumes too much knowledge of the repository setup if we don't supply
     *      a mechanism to discover this information.
     */
    public function setDocumentMetadata($documentId, $metadata)
    {
        $parameters = array($documentId, $this->formatMetadataInput($metadata));
        $result = $this->executeRequest('simple_metadata_update', $parameters);
    }

    private function formatMetadataInput($metadata)
    {
        $input = $metadata;
        $metadata = array();

        foreach ($input as $fieldset => $content) {
            $fieldsetIn['fieldset'] = $fieldset;
            $fieldsetIn['fields'] = $this->getMetadataInputFields($content);
            $metadata[] = $fieldsetIn;
        }

        return $metadata;
    }

    private function getMetadataInputFields($content)
    {
        $fields = array();

        foreach ($content as $fieldName => $data) {
            if (!empty($data['options'])) {
                $this->validateSelectedMetadataOption($fieldName, $data['value'], $data['options']);
            }

            $field = array();
            $field['name'] = $fieldName;
            $field['value'] = 'Tag' == $fieldName ? $this->formatTagInputValue($data['value']) : $data['value'];
            $fields[] = $field;
        }

        return $fields;
    }

    private function validateSelectedMetadataOption($field, $selected, $options)
    {
        if (empty($selected)) {
            return true;
        }

        foreach($options as $option) {
            if ($selected == $option) {
                return true;
            }
        }

        throw new KTWebserviceException("'$selected' is not a valid option for '$field'");
    }

    private function formatTagInputValue($values)
    {
        return implode(',', array_unique($values));
    }

    /**
     * Add a comment to a document's comment feed.
     *
     * @param int $documentId The id of the document to which comments will be added.
     * @param string $comment The comment to be added.
     */
    public function addComment($documentId, $comment)
    {
        $parameters = array($documentId, $comment);
        $this->executeRequest('add_document_comment', $parameters);
    }

    /**
     * Retrieve a list of existing comments for a document.
     *
     * @param int $documentId The id of the document for which to retrieve comments.
     * @param string $order [Optional] The order in which the comments are to be listed. 'ASC' or 'DESC'.
     *
     * @return array A listing of comments for the selected document.
     */
    public function getComments($documentId, $order = 'DESC')
    {
        $parameters = array($documentId);
        $result = $this->executeRequest('get_document_comments', $parameters);

        return $this->convertToArray($result->comments);
    }

    /**
     * Get the id of the current session.
     * You can then reuse this session id across scripts by calling reuseSession,
     * instead of initiating a new session each time.
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }

}

?>
