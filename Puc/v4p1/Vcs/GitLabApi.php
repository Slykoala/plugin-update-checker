<?php

if ( !class_exists('Puc_v4p1_Vcs_GitLabApi', false) ):

	class Puc_v4p1_Vcs_GitLabApi extends Puc_v4p1_Vcs_Api {

		/**
		 * @var string GitLab repository namespace.
		 */
		protected $repoNamespace;

		/**
		 * @var string Either a fully qualified repository URL, or just "user/repo-name".
		 */
		protected $repositoryUrl;

		/**
		 * @var string GitLab authentication token. Optional.
		 */
		protected $accessToken;

		public function __construct($repositoryUrl, $accessToken = null) {
			$path = @parse_url($repositoryUrl, PHP_URL_PATH);
			if ( preg_match('@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches) ) {
				$this->repoNamespace = $matches['username'] . '/' . $matches['repository'];
			} else {
				throw new InvalidArgumentException('Invalid GitLab repository URL: "' . $repositoryUrl . '"');
			}

			parent::__construct($repositoryUrl, $accessToken);
		}


		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Puc_v4p1_Vcs_Reference|null
		 */
		public function getLatestTag() {

			$tags = $this->api('/projects/:namespace/repository/tags');

			if ( is_wp_error($tags) || empty($tags) || !is_array($tags) ) {
				return null;
			}

			$versionTags = $this->sortTagsByVersion($tags);
			if ( empty($versionTags) ) {
				return null;
			}

			$tag = $versionTags[0];

			return new Puc_v4p1_Vcs_Reference(array(
				'name' => $tag->name,
				'version' => ltrim($tag->name, 'v'),
				'downloadUrl' => $this->buildArchiveDownloadUrl($tag->name),
			));
		}

		/**
		 * Get a branch by name.
		 *
		 * @param string $branchName
		 * @return null|Puc_v4p1_Vcs_Reference
		 */
		public function getBranch($branchName) {
			$branch = $this->api('/projects/:namespace/repository/branches/' . $branchName);
			if ( is_wp_error($branch) || empty($branch) ) {
				return null;
			}

			$reference = new Puc_v4p1_Vcs_Reference(array(
				'name' => $branch->name,
				'downloadUrl' => $this->buildArchiveDownloadUrl($branch->name),
			));

			if ( isset($branch->commit, $branch->commit->committed_date) ) {
				$reference->updated = $branch->commit->committed_date;
			}

			return $reference;
		}

		/**
		 * Get the latest commit that changed the specified file.
		 *
		 * @param string $filename
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return StdClass|null
		 */
		public function getLatestCommit($filename, $ref = 'master') {
			$commits = $this->api(
				'/projects/:namespace/repository/files/' . $filename,
				array(
					'ref' => $ref,
				)
			);
			if ( !is_wp_error($commits) && is_array($commits) && isset($commits[0]) ) {
				return $commits[0];
			}
			return null;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function getLatestCommitTime($ref) {
			$commits = $this->api('/projects/:namespace/repository/commits/', array('ref_name' => $ref));
			
			if ( !is_wp_error($commits) && is_array($commits) && isset($commits[0]) ) {
				return $commits[0]->committed_date;
			}
			return null;
		}

		/**
		 * Perform a GitLab API request.
		 *
		 * @param string $url
		 * @param array $queryParams
		 * @return mixed|WP_Error
		 */
		protected function api($url, $queryParams = array()) {
			$variables = array(
				'namespace' => $this->repoNamespace,
			);
			foreach ($variables as $name => $value) {
				$url = str_replace('/:' . $name, '/' . urlencode($value), $url);
			}
			$url = 'https://gitlab.com/api/v4' . $url;

			if ( !empty($this->accessToken) ) {
				$queryParams['private_token'] = $this->accessToken; 
			}
			if ( !empty($queryParams) ) {
				$url = add_query_arg($queryParams, $url);
			}

			$options = array('timeout' => 10);
			if ( !empty($this->httpFilterName) ) {
				$options = apply_filters($this->httpFilterName, $options);
			}
			$response = wp_remote_get($url, $options);
			if ( is_wp_error($response) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			if ( $code === 200 ) {
				$document = json_decode($body);
				return $document;
			}

			return new WP_Error(
				'puc-gitlab-http-error',
				'GitLab API error. HTTP status: ' . $code
			);
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function getRemoteFile($path, $ref = 'master') {
			$apiUrl = '/projects/:namespace/repository/files/' . $path;
			$response = $this->api($apiUrl, array('ref' => $ref));

			if ( is_wp_error($response) || !isset($response->content) || ($response->encoding !== 'base64') ) {
				return null;
			}
			return base64_decode($response->content);
		}

		/**
		 * Generate a URL to download a ZIP archive of the specified branch/tag/etc.
		 *
		 * @param string $ref
		 * @return string
		 */
		public function buildArchiveDownloadUrl($ref = 'master') {
			$url = sprintf(
				'https://gitlab.com/%1$s/repository/archive.zip?ref=%2$s',
				urlencode($this->repoNamespace),
				urlencode($ref)
			);
			if ( !empty($this->accessToken) ) {
				$url = $this->signDownloadUrl($url);
			}
			return $url;
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return Puc_v4p1_Vcs_Reference|null
		 */
		public function getTag($tagName) {
			//The current GitLab update checker doesn't use getTag, so I didn't bother to implement it.
			throw new LogicException('The ' . __METHOD__ . ' method is not implemented and should not be used.');
		}

		public function setAuthentication($credentials) {
			parent::setAuthentication($credentials);
			$this->accessToken = is_string($credentials) ? $credentials : null;
		}

		/**
		 * Figure out which reference (i.e tag or branch) contains the latest version.
		 *
		 * @param string $configBranch Start looking in this branch.
		 * @return null|Puc_v4p1_Vcs_Reference
		 */
		public function chooseReference($configBranch) {
			$updateSource = null;

			//var_dump($configBranch);exit;

			if ( $configBranch === 'master' ) {
				//Failing that, use the tag with the highest version number.
				$updateSource = $this->getLatestTag();
			}
			//Alternatively, just use the branch itself.
			if ( empty($updateSource) ) {
				$updateSource = $this->getBranch($configBranch);
			}

			return $updateSource;
		}

		/**
		 * @param string $url
		 * @return string
		 */
		public function signDownloadUrl($url) {
			if ( empty($this->credentials) ) {
				return $url;
			}
			return add_query_arg('private_token', $this->credentials, $url);
		}

	}

endif;