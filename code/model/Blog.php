<?php

/**
 * Blog Holder
 *
 * @package silverstripe
 * @subpackage blog
 * 
 * @method HasManyList Tags() List of tags in this blog
 * @method HasManyList Categories() List of categories in this blog
 * @method ManyManyList Editors() List of editors
 * @method ManyManyList Writers() List of writers
 * @method ManyManyList Contributors() List of contributors
 *
 * @author Michael Strong <github@michaelstrong.co.uk>
**/
class Blog extends Page implements PermissionProvider {

	/**
	 * Permission for user management
	 *
	 * @var string
	 */
	const MANAGE_USERS = 'BLOG_MANAGE_USERS';

	/**
	 * If true, users assigned as editor, writer, or contributor will be automatically
	 * granted CMS_ACCESS_CMSMain permission. If false, only users with this permission
	 * already may be assigned.
	 *
	 * @var boolean
	 * @config
	 */
	private static $grant_user_access = true;

	/**
	 * Permission to either require, or grant to users assigned to work on this blog
	 *
	 * @var string
	 * @config
	 */
	private static $grant_user_permission = 'CMS_ACCESS_CMSMain';

	/**
	 * Group code to assign newly granted users toh
	 *
	 * @var string
	 * @config
	 */
	private static $grant_user_group = 'blog-users';

	private static $db = array(
		"PostsPerPage" => "Int",
	);

	private static $has_many = array(
		"Tags" => "BlogTag",
		"Categories" => "BlogCategory",
	);

	private static $many_many = array(
		'Editors' => 'Member',
		'Writers' => 'Member',
		'Contributors' => 'Member',
	);
	
	private static $allowed_children = array(
		"BlogPost",
	);

	private static $extensions = array(
		"BlogFilter",
	);

	private static $defaults = array(
		"ProvideComments" => false,
	);

	private static $description = 'Adds a blog to your website.';

	public function getCMSFields() {
		$self =& $this;
		$this->beforeUpdateCMSFields(function($fields) use ($self) {

			// Don't show this tab if edit is not allowed
			if(!$self->canEdit()) return;

			// Create categories and tag config
			$config = GridFieldConfig_RecordEditor::create();
			$config->removeComponentsByType("GridFieldAddNewButton");
			$config->addComponent(new GridFieldAddByDBField("buttons-before-left"));

			$categories = GridField::create(
				"Categories",
				_t("Blog.Categories", "Categories"),
				$self->Categories(),
				$config
			);

			$tags = GridField::create(
				"Tags",
				_t("Blog.Tags", "Tags"),
				$self->Tags(),
				$config
			);

			$fields->addFieldsToTab("Root.BlogOptions", array(
				$categories,
				$tags
			));
		});
		
		$fields = parent::getCMSFields();
		return $fields;
	}

	/**
	 * Check if this member is an editor of the blog
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function isEditor($member) {
		$isEditor = $this->isMemberOf($member, $this->Editors());
		$this->extend('updateIsEditor', $isEditor, $member);
		return $isEditor;
	}

	/**
	 * Check if this member is a writer of the blog
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function isWriter($member) {
		$isWriter = $this->isMemberOf($member, $this->Writers());
		$this->extend('updateIsWriter', $isWriter, $member);
		return $isWriter;
	}

	/**
	 * Check if this member is a contributor of the blog
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function isContributor($member) {
		$isContributor = $this->isMemberOf($member, $this->Contributors());
		$this->extend('updateIsContributor', $isContributor, $member);
		return $isContributor;
	}

	/**
	 * Determine if the given member belongs to the given subrelation
	 *
	 * @param Member $member
	 * @param DataList $list Relation to check
	 * @return boolean
	 */
	protected function isMemberOf($member, $list) {
		if(!$member || !$member->exists()) return false;

		if($list instanceof UnsavedRelationList) {
			return in_array($member->ID, $list->getIDList());
		}

		return $list->byID($member->ID) !== null;
	}



	public function canEdit($member = null) {
		$member = $member ?: Member::currentUser();
		if(is_numeric($member)) $member = Member::get()->byID($member);
		
		// Allow editors to edit this page
		if($this->isEditor($member)) return true;

		return parent::canEdit($member);
	}

	/**
	 * Determine if this user can edit the editors list
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function canEditEditors($member = null) {
		$member = $member ?: Member::currentUser();

		$extended = $this->extendedCan('canEditEditors', $member);
		if($extended !== null) return $extended;

		// Check permission
		return Permission::checkMember($member, self::MANAGE_USERS);
	}

	/**
	 * Determine if this user can edit writers list
	 *
	 * @param Member $member
	 * @return boolean
	 */
	public function canEditWriters($member = null) {
		$member = $member ?: Member::currentUser();
		if(is_numeric($member)) $member = Member::get()->byID($member);

		$extended = $this->extendedCan('canEditWriters', $member);
		if($extended !== null) return $extended;

		// Editors can edit writers
		if($this->isEditor($member)) return true;

		// Check permission
		return Permission::checkMember($member, self::MANAGE_USERS);
	}

	/**
	 * Determines if this user can edit the contributors list
	 *
	 * @param type $member
	 * @return boolean
	 */
	public function canEditContributors($member = null) {
		$member = $member ?: Member::currentUser();
		if(is_numeric($member)) $member = Member::get()->byID($member);
		
		$extended = $this->extendedCan('canEditContributors', $member);
		if($extended !== null) return $extended;

		// Editors can edit writers
		if($this->isEditor($member)) return true;

		// Check permission
		return Permission::checkMember($member, self::MANAGE_USERS);
	}

	public function canAddChildren($member = null) {
		$member = $member ?: Member::currentUser();
		if(is_numeric($member)) $member = Member::get()->byID($member);

		// Editors, Writers and Contributors can add children
		if($this->isEditor($member) || $this->isWriter($member) || $this->isContributor($member)) {
			return true;
		}

		return parent::canAddChildren($member);
	}


	public function getSettingsFields() {
		$fields = parent::getSettingsFields();
		$fields->addFieldToTab("Root.Settings", 
			NumericField::create("PostsPerPage", _t("Blog.PostsPerPage", "Posts Per Page"))
		);

		// Roles and Permissions
		$members = $this->getCandidateUsers()->map()->toArray();

		// Editors
		$editorField = ListboxField::create('Editors', 'Editors', $members)
			->setMultiple(true)
			->setDescription('Editors are able to manage this blog and its posts');
		if(!$this->canEditEditors()) {
			$editorField = $editorField->performDisabledTransformation();
		}

		// Writers
		$writerField = ListboxField::create('Writers', 'Writers', $members)
			->setMultiple(true)
			->setDescription('Writers are able to write and publish posts');
		if(!$this->canEditWriters()) {
			$writerField = $writerField->performDisabledTransformation();
		}

		// Contributors
		$contributorField = ListboxField::create('Contributors', 'Contributors', $members)
			->setMultiple(true)
			->setDescription('Contributors are able to write, but not publish, posts');
		if(!$this->canEditContributors()) {
			$contributorField = $contributorField->performDisabledTransformation();
		}
		
		$fields->addFieldsToTab('Root.Users', array(
			$editorField,
			$writerField,
			$contributorField
		));

		return $fields;
	}


	/**
	 * Return blog posts
	 *
	 * @return DataList of BlogPost objects
	**/
	public function getBlogPosts() {
		$blogPosts = BlogPost::get()->filter("ParentID", $this->ID);
		//Allow decorators to manipulate list
		$this->extend('updateGetBlogPosts', $blogPosts);
		return $blogPosts;
	}



	/**
	 * Returns blogs posts for a given date period.
	 *
	 * @param $year int
	 * @param $month int
	 * @param $day int
	 *
	 * @return DataList
	**/
	public function getArchivedBlogPosts($year, $month = null, $day = null) {
		$query = $this->getBlogPosts()->dataQuery();

		$stage = $query->getQueryParam("Versioned.stage");
		if($stage) $stage = '_' . Convert::raw2sql($stage);

		$query->innerJoin("BlogPost", "`SiteTree" . $stage . "`.`ID` = `BlogPost" . $stage . "`.`ID`");
		$query->where("YEAR(PublishDate) = '" . Convert::raw2sql($year) . "'");
		if($month) {
			$query->where("MONTH(PublishDate) = '" . Convert::raw2sql($month) . "'");
			if($day) {
				$query->where("DAY(PublishDate) = '" . Convert::raw2sql($day) . "'");
			}
		}

		return $this->getBlogPosts()->setDataQuery($query);
	}


	/**
	 * Get a link to a Member profile.
	 * 
	 * @param urlSegment
	 * @return String
	 */
	public function ProfileLink($urlSegment) {
		return Controller::join_links($this->Link(), 'profile', $urlSegment);
	}


	/**
	 * This sets the title for our gridfield
	 *
	 * @return string
	 */
	public function getLumberjackTitle() {
		return _t('Blog.LumberjackTitle', 'Blog Posts');
	}



	/**
	 * This overwrites lumberjacks default gridfield config.
	 *
	 * @return GridFieldConfig
	 */
	public function getLumberjackGridFieldConfig() {
		return GridFieldConfig_BlogPost::create();
	}

	public function providePermissions() {
		return array(
			Blog::MANAGE_USERS => array(
				'name' => _t(
					'Blog.PERMISSION_MANAGE_USERS_DESCRIPTION',
					'Manage users for individual blogs'
				),
				'help' => _t(
					'Blog.PERMISSION_MANAGE_USERS_HELP',
					'Allow assignment of Editors, Writers, or Contributors to blogs'
				),
				'category' => _t('Blog.PERMISSIONS_CATEGORY', 'Blog permissions'),
				'sort' => 100
			)
		);
	}

	/**
	 * Gets the list of user candidates to be assigned to assist with this blog
	 *
	 * @return SS_List
	 */
	protected function getCandidateUsers() {
		if($this->config()->grant_user_access) {
			// If we are allowed to grant CMS access, all users are candidates
			return Member::get();
		} else {
			// If access cannot be granted, limit users to those who can access the CMS
			// This is useful for more secure sites
			$permission = $this->config()->grant_user_permission;
			return Permission::get_members_by_permission($permission);
		}
	}

	/**
	 * Gets or creates the group used to assign CMS access
	 *
	 * @return Group
	 */
	protected function getUserGroup() {
		$code = $this->config()->grant_user_group;
		$group = Group::get()->filter('Code', $code)->first();
		if($group) return $group;

		// Create new group
		$group = new Group();
		$group->Title = 'Blog users';
		$group->Code = $code;
		$group->write();

		// Add permission
		$permission = new Permission();
		$permission->Code = $this->config()->grant_user_permission;
		$group->Permissions()->add($permission);

		return $group;
	}

	protected function onBeforeWrite() {
		parent::onBeforeWrite();
		$this->assignGroup();
	}

	/**
	 * Assign users as necessary to the blog group
	 */
	protected function assignGroup() {
		// Ensure that any user granted editor, writer, or contributor have CMS_ACCESS_CMSMain access
		if(!$this->config()->grant_user_access) return;

		// Generate or retrieve the group
		$group = $this->getUserGroup();
		foreach(array($this->Editors(), $this->Writers(), $this->Contributors()) as $userlist) {
			foreach($userlist as $user) {
				// Ensure user exists in the group
				if(!$user->inGroup($group)) $user->Groups()->add($group);
			}
		}
	}

}



/**
 * Blog Controller
 *
 * @package silverstripe
 * @subpackage blog
 *
 * @author Michael Strong <github@michaelstrong.co.uk>
**/
class Blog_Controller extends Page_Controller {

	private static $allowed_actions = array(
		'archive',
		'tag',
		'category',
		'rss',
		'profile'
	);

	private static $url_handlers = array(
		'tag/$Tag!' => 'tag',
		'category/$Category!' => 'category',
		'archive/$Year!/$Month/$Day' => 'archive',
		'profile/$URLSegment!' => 'profile'
	);


	/** 
	 * The current Blog Post DataList query.
	 *
	 * @var DataList
	**/
	protected $blogPosts;



	public function index() {
		$this->blogPosts = $this->getBlogPosts();
		return $this->render();
	}

	/**
	 * Renders a Blog Member's profile.
	 *
	 * @return SS_HTTPResponse
	**/
	public function profile() {
		$profile = $this->getCurrentProfile();

		if(!$profile) {
			return $this->httpError(404, 'Not Found');
		}

		$this->blogPosts = $this->getCurrentProfilePosts();

		return $this->render();
	}

	/**
	 * Get the Member associated with the current URL segment.
	 * 
	 * @return Member|null
	**/
	public function getCurrentProfile() {
		$urlSegment = $this->request->param('URLSegment');

		if($urlSegment) {
			return Member::get()
				->filter('URLSegment', $urlSegment)
				->first();
		}

		return null;
	}

	/**
	 * Get posts related to the current Member profile
	 * 
	 * @return DataList|null
	**/
	public function getCurrentProfilePosts() {
		$profile = $this->getCurrentProfile();

		if($profile) {
			return $profile->AuthoredPosts()->filter('ParentID', $this->ID);
		}

		return null;
	}

	/**
	 * Renders an archive for a specificed date. This can be by year or year/month
	 *
	 * @return SS_HTTPResponse
	**/
	public function archive() {
		$year = $this->getArchiveYear();
		$month = $this->getArchiveMonth();
		$day = $this->getArchiveDay();

		// If an invalid month has been passed, we can return a 404.
		if($this->request->param("Month") && !$month) {
			return $this->httpError(404, "Not Found");
		}

		// Check for valid day
		if($month && $this->request->param("Day") && !$day) {
			return $this->httpError(404, "Not Found");
		}

		if($year) {
			$this->blogPosts = $this->getArchivedBlogPosts($year, $month, $day);
			return $this->render();
		}
		return $this->httpError(404, "Not Found");
	}



	/**
	 * Renders the blog posts for a given tag.
	 *
	 * @return SS_HTTPResponse
	**/
	public function tag() {
		$tag = $this->getCurrentTag();
		if($tag) {
			$this->blogPosts = $tag->BlogPosts();
			return $this->render();
		}
		return $this->httpError(404, "Not Found");
	}



	/**
	 * Renders the blog posts for a given category
	 *
	 * @return SS_HTTPResponse
	**/
	public function category() {
		$category = $this->getCurrentCategory();
		if($category) {
			$this->blogPosts = $category->BlogPosts();
			return $this->render();
		}
		return $this->httpError(404, "Not Found");
	}



	/**
	 * Displays an RSS feed of blog posts
	 *
	 * @return string HTML
	**/
	public function rss() {
		$rss = new RSSFeed($this->getBlogPosts(), $this->Link(), $this->MetaTitle, $this->MetaDescription);
		$this->extend('updateRss', $rss);
		return $rss->outputToBrowser();
	}



	/**
	 * Returns a list of paginated blog posts based on the blogPost dataList
	 *
	 * @return PaginatedList
	**/
	public function PaginatedList() {
		$posts = new PaginatedList($this->blogPosts);

		// If pagination is set to '0' then no pagination will be shown.
		if($this->PostsPerPage > 0) {
			$posts->setPageLength($this->PostsPerPage);
		} else {
			$pageSize = $this->getBlogPosts()->count() ?: 99999;
			$posts->setPageLength($pageSize);
		}

		$start = $this->request->getVar($posts->getPaginationGetVar());
		$posts->setPageStart($start);

		return $posts;
	}



	/**
	 * Tag Getter for use in templates.
	 *
	 * @return BlogTag|null
	**/
	public function getCurrentTag() {
		$tag = $this->request->param("Tag");
		if($tag) {
			return $this->dataRecord->Tags()
				->filter("URLSegment", $tag)
				->first();
		}
		return null;
	}



	/**
	 * Category Getter for use in templates.
	 *
	 * @return BlogCategory|null
	**/
	public function getCurrentCategory() {
		$category = $this->request->param("Category");
		if($category) {
			return $this->dataRecord->Categories()
				->filter("URLSegment", $category)
				->first();
		}
		return null;
	}



	/**
	 * Fetches the archive year from the url
	 *
	 * @return int|null
	**/
	public function getArchiveYear() {
		$year = $this->request->param("Year");
		if(preg_match("/^[0-9]{4}$/", $year)) {
			return (int) $year;
		}
		return null;
	}



	/**
	 * Fetches the archive money from the url.
	 *
	 * @return int|null
	**/
	public function getArchiveMonth() {
		$month = $this->request->param("Month");
		if(preg_match("/^[0-9]{1,2}$/", $month)) {
			if($month > 0 && $month < 13) {
				// Check that we have a valid date.
				if(checkdate($month, 01, $this->getArchiveYear())) {
					return (int) $month;
				}
			}
		}
		return null;
	}



	/**
	 * Fetches the archive day from the url
	 *
	 * @return int|null
	**/
	public function getArchiveDay() {
		$day = $this->request->param("Day");
		if(preg_match("/^[0-9]{1,2}$/", $day)) {

			// Check that we have a valid date
			if(checkdate($this->getArchiveMonth(), $day, $this->getArchiveYear())) {
				return (int) $day;
			}
		}
		return null;
	}



	/**
	 * Returns the current archive date.
	 *
	 * @return Date
	**/
	public function getArchiveDate() {
		$year = $this->getArchiveYear();
		$month = $this->getArchiveMonth();
		$day = $this->getArchiveDay();

		if($year) {
			if($month) {
				$date = $year . '-' . $month . '-01';
				if($day) {
					$date = $year . '-' . $month . '-' . $day;
				}
			} else {
				$date = $year . '-01-01';
			}
			return DBField::create_field("Date", $date);
		}
	}



	/**
	 * Returns a link to the RSS feed.
	 *
	 * @return string URL
	**/
	public function getRSSLink() {
		return $this->Link("rss");
	}

}
