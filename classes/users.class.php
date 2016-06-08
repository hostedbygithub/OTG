<?
class Users {
	/**
	 * Get $Classes (list of classes keyed by ID) and $ClassLevels
	 *		(list of classes keyed by level)
	 * @return array ($Classes, $ClassLevels)
	 */
	public static function get_classes() {
		global $Debug;
		// Get permissions
		list($Classes, $ClassLevels) = G::$Cache->get_value('classes');
		if (!$Classes || !$ClassLevels) {
			$QueryID = G::$DB->get_query_id();
			G::$DB->query('
				SELECT ID, Name, Abbreviation, Level, Secondary
				FROM permissions
				ORDER BY Level');
			$Classes = G::$DB->to_array('ID');
			$ClassLevels = G::$DB->to_array('Level');
			G::$DB->set_query_id($QueryID);
			G::$Cache->cache_value('classes', array($Classes, $ClassLevels), 0);
		}
		$Debug->set_flag('Loaded permissions');

		return array($Classes, $ClassLevels);
	}


	/**
	 * Get user info, is used for the current user and usernames all over the site.
	 *
	 * @param $UserID int   The UserID to get info for
	 * @return array with the following keys:
	 *	int	ID
	 *	string	Username
	 *	int	PermissionID
	 *	array	Paranoia - $Paranoia array sent to paranoia.class
	 *	boolean	Artist
	 *	boolean	Donor
	 *	string	Warned - When their warning expires in international time format
	 *	string	Avatar - URL
	 *	boolean	Enabled
	 *	string	Title
	 *	string	CatchupTime - When they last caught up on forums
	 *	boolean	Visible - If false, they don't show up on peer lists
	 *	array ExtraClasses - Secondary classes.
	 *	int EffectiveClass - the highest level of their main and secondary classes
	 */
	public static function user_info($UserID) {
		global $Classes;
		$UserInfo = G::$Cache->get_value("user_info_$UserID");
		// the !isset($UserInfo['Paranoia']) can be removed after a transition period
		if (empty($UserInfo) || empty($UserInfo['ID']) || !isset($UserInfo['Paranoia']) || empty($UserInfo['Class'])) {
			$OldQueryID = G::$DB->get_query_id();

			G::$DB->query("
				SELECT
					m.ID,
					m.Username,
					m.PermissionID,
					m.Paranoia,
					i.Artist,
					i.Donor,
					i.Warned,
					i.Avatar,
					m.Enabled,
					m.Title,
					i.CatchupTime,
					m.Visible,
          la.Type AS LockedAccount,
					GROUP_CONCAT(ul.PermissionID SEPARATOR ',') AS Levels
				FROM users_main AS m
					INNER JOIN users_info AS i ON i.UserID = m.ID
          LEFT JOIN locked_accounts AS la ON la.UserID = m.ID
					LEFT JOIN users_levels AS ul ON ul.UserID = m.ID
				WHERE m.ID = '$UserID'
				GROUP BY m.ID");

			if (!G::$DB->has_results()) { // Deleted user, maybe?
				$UserInfo = array(
						'ID' => $UserID,
						'Username' => '',
						'PermissionID' => 0,
						'Paranoia' => array(),
						'Artist' => false,
						'Donor' => false,
						'Warned' => '0000-00-00 00:00:00',
						'Avatar' => '',
						'Enabled' => 0,
						'Title' => '',
						'CatchupTime' => 0,
						'Visible' => '1',
						'Levels' => '',
						'Class' => 0);
			} else {
				$UserInfo = G::$DB->next_record(MYSQLI_ASSOC, array('Paranoia', 'Title'));
				$UserInfo['CatchupTime'] = strtotime($UserInfo['CatchupTime']);
				$UserInfo['Paranoia'] = unserialize($UserInfo['Paranoia']);
				if ($UserInfo['Paranoia'] === false) {
					$UserInfo['Paranoia'] = array();
				}
				$UserInfo['Class'] = $Classes[$UserInfo['PermissionID']]['Level'];
			}

      if (isset($UserInfo['LockedAccount']) && $UserInfo['LockedAccount'] == "") {
        unset($UserInfo['LockedAccount']);
      }

			if (!empty($UserInfo['Levels'])) {
				$UserInfo['ExtraClasses'] = array_fill_keys(explode(',', $UserInfo['Levels']), 1);
			} else {
				$UserInfo['ExtraClasses'] = array();
			}
			unset($UserInfo['Levels']);
			$EffectiveClass = $UserInfo['Class'];
			foreach ($UserInfo['ExtraClasses'] as $Class => $Val) {
				$EffectiveClass = max($EffectiveClass, $Classes[$Class]['Level']);
			}
			$UserInfo['EffectiveClass'] = $EffectiveClass;

			G::$Cache->cache_value("user_info_$UserID", $UserInfo, 2592000);
			G::$DB->set_query_id($OldQueryID);
		}
		if (strtotime($UserInfo['Warned']) < time()) {
			$UserInfo['Warned'] = '0000-00-00 00:00:00';
			G::$Cache->cache_value("user_info_$UserID", $UserInfo, 2592000);
		}

		return $UserInfo;
	}

	/**
	 * Gets the heavy user info
	 * Only used for current user
	 *
	 * @param $UserID The userid to get the information for
	 * @return fetched heavy info.
	 *		Just read the goddamn code, I don't have time to comment this shit.
	 */
	public static function user_heavy_info($UserID) {

		$HeavyInfo = G::$Cache->get_value("user_info_heavy_$UserID");
		if (empty($HeavyInfo)) {

			$QueryID = G::$DB->get_query_id();
			G::$DB->query("
				SELECT
					m.Invites,
					m.torrent_pass,
					m.IP,
					m.CustomPermissions,
					m.can_leech AS CanLeech,
					i.AuthKey,
					i.RatioWatchEnds,
					i.RatioWatchDownload,
					i.StyleID,
					i.StyleURL,
					i.DisableInvites,
					i.DisablePosting,
					i.DisableUpload,
					i.DisableWiki,
					i.DisableAvatar,
					i.DisablePM,
					i.DisableNips,
					i.DisableRequests,
					i.DisableForums,
					i.DisableTagging," . "
					i.SiteOptions,
					i.DownloadAlt,
					i.LastReadNews,
					i.LastReadBlog,
					i.RestrictedForums,
					i.PermittedForums,
					m.FLTokens,
					m.BonusPoints,
					m.HnR,
					m.PermissionID
				FROM users_main AS m
					INNER JOIN users_info AS i ON i.UserID = m.ID
				WHERE m.ID = '$UserID'");
			$HeavyInfo = G::$DB->next_record(MYSQLI_ASSOC, array('CustomPermissions', 'SiteOptions'));

			if (!empty($HeavyInfo['CustomPermissions'])) {
				$HeavyInfo['CustomPermissions'] = unserialize($HeavyInfo['CustomPermissions']);
			} else {
				$HeavyInfo['CustomPermissions'] = array();
			}

			if (!empty($HeavyInfo['RestrictedForums'])) {
				$RestrictedForums = array_map('trim', explode(',', $HeavyInfo['RestrictedForums']));
			} else {
				$RestrictedForums = array();
			}
			unset($HeavyInfo['RestrictedForums']);
			if (!empty($HeavyInfo['PermittedForums'])) {
				$PermittedForums = array_map('trim', explode(',', $HeavyInfo['PermittedForums']));
			} else {
				$PermittedForums = array();
			}
			unset($HeavyInfo['PermittedForums']);

			G::$DB->query("
				SELECT PermissionID
				FROM users_levels
				WHERE UserID = $UserID");
			$PermIDs = G::$DB->collect('PermissionID');
			foreach ($PermIDs AS $PermID) {
				$Perms = Permissions::get_permissions($PermID);
				if (!empty($Perms['PermittedForums'])) {
					$PermittedForums = array_merge($PermittedForums, array_map('trim', explode(',', $Perms['PermittedForums'])));
				}
			}
			$Perms = Permissions::get_permissions($HeavyInfo['PermissionID']);
			unset($HeavyInfo['PermissionID']);
			if (!empty($Perms['PermittedForums'])) {
				$PermittedForums = array_merge($PermittedForums, array_map('trim', explode(',', $Perms['PermittedForums'])));
			}

			if (!empty($PermittedForums) || !empty($RestrictedForums)) {
				$HeavyInfo['CustomForums'] = array();
				foreach ($RestrictedForums as $ForumID) {
					$HeavyInfo['CustomForums'][$ForumID] = 0;
				}
				foreach ($PermittedForums as $ForumID) {
					$HeavyInfo['CustomForums'][$ForumID] = 1;
				}
			} else {
				$HeavyInfo['CustomForums'] = null;
			}
			if (isset($HeavyInfo['CustomForums'][''])) {
				unset($HeavyInfo['CustomForums']['']);
			}

			$HeavyInfo['SiteOptions'] = unserialize($HeavyInfo['SiteOptions']);
			if (!empty($HeavyInfo['SiteOptions'])) {
				$HeavyInfo = array_merge($HeavyInfo, $HeavyInfo['SiteOptions']);
			}
			unset($HeavyInfo['SiteOptions']);

			G::$DB->set_query_id($QueryID);

			G::$Cache->cache_value("user_info_heavy_$UserID", $HeavyInfo, 0);
		}
		return $HeavyInfo;
	}

	/**
	 * Updates the site options in the database
	 *
	 * @param int $UserID the UserID to set the options for
	 * @param array $NewOptions the new options to set
	 * @return false if $NewOptions is empty, true otherwise
	 */
	public static function update_site_options($UserID, $NewOptions) {
		if (!is_number($UserID)) {
			error(0);
		}
		if (empty($NewOptions)) {
			return false;
		}

		$QueryID = G::$DB->get_query_id();

		// Get SiteOptions
		G::$DB->query("
			SELECT SiteOptions
			FROM users_info
			WHERE UserID = $UserID");
		list($SiteOptions) = G::$DB->next_record(MYSQLI_NUM, false);
		$SiteOptions = unserialize($SiteOptions);

		// Get HeavyInfo
		$HeavyInfo = Users::user_heavy_info($UserID);

		// Insert new/replace old options
		$SiteOptions = array_merge($SiteOptions, $NewOptions);
		$HeavyInfo = array_merge($HeavyInfo, $NewOptions);

		// Update DB
		G::$DB->query("
			UPDATE users_info
			SET SiteOptions = '".db_string(serialize($SiteOptions))."'
			WHERE UserID = $UserID");
		G::$DB->set_query_id($QueryID);

		// Update cache
		G::$Cache->cache_value("user_info_heavy_$UserID", $HeavyInfo, 0);

		// Update G::$LoggedUser if the options are changed for the current
		if (G::$LoggedUser['ID'] == $UserID) {
			G::$LoggedUser = array_merge(G::$LoggedUser, $NewOptions);
			G::$LoggedUser['ID'] = $UserID; // We don't want to allow userid switching
		}
		return true;
	}

	/**
	 * Generates a check list of release types, ordered by the user or default
	 * @param array $SiteOptions
	 * @param boolean $Default Returns the default list if true
	 */
	public static function release_order(&$SiteOptions, $Default = false) {
		global $ReleaseTypes;

		$RT = $ReleaseTypes + array(
			1024 => 'Guest Appearance',
			1023 => 'Remixed By',
			1022 => 'Composition',
			1021 => 'Produced By');

		if ($Default || empty($SiteOptions['SortHide'])) {
			$Sort =& $RT;
			$Defaults = !empty($SiteOptions['HideTypes']);
		} else {
			$Sort =& $SiteOptions['SortHide'];
			$MissingTypes = array_diff_key($RT, $Sort);
			if (!empty($MissingTypes)) {
				foreach (array_keys($MissingTypes) as $Missing) {
					$Sort[$Missing] = 0;
				}
			}
		}

		foreach ($Sort as $Key => $Val) {
			if (isset($Defaults)) {
				$Checked = $Defaults && isset($SiteOptions['HideTypes'][$Key]) ? ' checked="checked"' : '';
			} else {
				if (!isset($RT[$Key])) {
					continue;
				}
				$Checked = $Val ? ' checked="checked"' : '';
				$Val = $RT[$Key];
			}

			$ID = $Key. '_' . (int)(!!$Checked);

							// The HTML is indented this far for proper indentation in the generated HTML
							// on user.php?action=edit
?>
							<li class="sortable_item">
								<label><input type="checkbox"<?=$Checked?> id="<?=$ID?>" /> <?=$Val?></label>
							</li>
<?
		}
	}

	/**
	 * Returns the default order for the sort list in a JS-friendly string
	 * @return string
	 */
	public static function release_order_default_js(&$SiteOptions) {
		ob_start();
		self::release_order($SiteOptions, true);
		$HTML = ob_get_contents();
		ob_end_clean();
		return json_encode($HTML);
	}

	/**
	 * Generate a random string
	 *
	 * @param Length
	 * @return random alphanumeric string
	 */
	public static function make_secret($Length = 32) {
		$Secret = '';
		$Chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		for ($i = 0; $i < $Length; $i++) {
			$Secret .= $Chars[random_int(0, strlen($Chars)-1)];
		}
		return str_shuffle($Secret);
	}

	/**
	 * Verify a password against a password hash
	 *
	 * @param $Password password
	 * @param $Hash password hash
	 * @return true on correct password
	 */
	public static function check_password($Password, $Hash) {
		if (!$Password || !$Hash) { return false; }
	  return password_verify(base64_encode(hash("sha512", $Password)), $Hash);
	}

	/**
	 * Create salted hash for a given string
	 *
	 * @param $Str string to hash
	 * @return salted hash
	 */
  public static function make_sec_hash($Str) {
    return password_hash(base64_encode(hash("sha512", $Str)), PASSWORD_DEFAULT);
  }

	/**
	 * Returns a username string for display
	 *
	 * @param int $UserID
	 * @param boolean $Badges whether or not badges (donor, warned, enabled) should be shown
	 * @param boolean $IsWarned
	 * @param boolean $IsEnabled
	 * @param boolean $Class whether or not to show the class
	 * @param boolean $Title whether or not to show the title
	 * @param boolean $IsDonorForum for displaying donor forum honorific prefixes and suffixes
	 * @return HTML formatted username
	 */
	public static function format_username($UserID, $Badges = false, $IsWarned = true, $IsEnabled = true, $Class = false, $Title = false, $IsDonorForum = false) {
		global $Classes;

		// This array is a hack that should be made less retarded, but whatevs
		// 						  PermID => ShortForm

		$SecondaryClasses = array(
								 );

		if ($UserID == 0) {
			return 'System';
		}

		$UserInfo = self::user_info($UserID);
		if ($UserInfo['Username'] == '') {
			return "Unknown [$UserID]";
		}

		$Str = '';

		$Username = $UserInfo['Username'];
		$Paranoia = $UserInfo['Paranoia'];

		if ($UserInfo['Class'] < $Classes[MOD]['Level']) {
			$OverrideParanoia = check_perms('users_override_paranoia', $UserInfo['Class']);
		} else {
			// Don't override paranoia for mods who don't want to show their donor heart
			$OverrideParanoia = false;
		}
		$ShowDonorIcon = (!in_array('hide_donor_heart', $Paranoia) || $OverrideParanoia);

		if ($IsDonorForum) {
			list($Prefix, $Suffix, $HasComma) = Donations::get_titles($UserID);
			$Username = "$Prefix $Username" . ($HasComma ? ', ' : ' ') . "$Suffix ";
		}

		if ($Title) {
			$Str .= "<strong><a href=\"user.php?id=$UserID\">$Username</a></strong>";
		} else {
			$Str .= "<a href=\"user.php?id=$UserID\">$Username</a>";
		}
		if ($Badges) {
			$DonorRank = Donations::get_rank($UserID);
			if ($DonorRank == 0 && $UserInfo['Donor'] == 1) {
				$DonorRank = 1;
			}
			if ($ShowDonorIcon && $DonorRank > 0) {
				$IconLink = 'donate.php';
				$IconImage = 'donor.png';
				$IconText = 'Donor';
				$DonorHeart = $DonorRank;
				$SpecialRank = Donations::get_special_rank($UserID);
				$EnabledRewards = Donations::get_enabled_rewards($UserID);
				$DonorRewards = Donations::get_rewards($UserID);
				if ($EnabledRewards['HasDonorIconMouseOverText'] && !empty($DonorRewards['IconMouseOverText'])) {
					$IconText = display_str($DonorRewards['IconMouseOverText']);
				}
				if ($EnabledRewards['HasDonorIconLink'] && !empty($DonorRewards['CustomIconLink'])) {
					$IconLink = display_str($DonorRewards['CustomIconLink']);
				}
				if ($EnabledRewards['HasCustomDonorIcon'] && !empty($DonorRewards['CustomIcon'])) {
					$IconImage = ImageTools::process($DonorRewards['CustomIcon'], false, 'donoricon', $UserID);
				} else {
					if ($SpecialRank === MAX_SPECIAL_RANK) {
						$DonorHeart = 6;
					} elseif ($DonorRank === 5) {
						$DonorHeart = 4; // Two points between rank 4 and 5
					} elseif ($DonorRank >= MAX_RANK) {
						$DonorHeart = 5;
					}
					if ($DonorHeart === 1) {
						$IconImage = STATIC_SERVER . 'common/symbols/donor.png';
					} else {
						$IconImage = STATIC_SERVER . "common/symbols/donor_{$DonorHeart}.png";
					}
				}
				$Str .= "<a target=\"_blank\" href=\"$IconLink\"><img class=\"donor_icon tooltip\" src=\"$IconImage\" alt=\"$IconText\" title=\"$IconText\" /></a>";
			}
			$Str .= Badges::display_badges(Badges::get_displayed_badges($UserID), true);
		}

		$Str .= ($IsWarned && $UserInfo['Warned'] != '0000-00-00 00:00:00') ? '<a href="wiki.php?action=article&amp;id=218"'
					. '><img src="'.STATIC_SERVER.'common/symbols/warned.png" alt="Warned" title="Warned'
					. (G::$LoggedUser['ID'] === $UserID ? ' - Expires ' . date('Y-m-d H:i', strtotime($UserInfo['Warned'])) : '')
					. '" class="tooltip" /></a>' : '';
		$Str .= ($IsEnabled && $UserInfo['Enabled'] == 2) ? '<a href="rules.php"><img src="'.STATIC_SERVER.'common/symbols/disabled.png" alt="Banned" title="Be good, and you won\'t end up like this user" class="tooltip" /></a>' : '';

		if ($Badges) {
			$ClassesDisplay = array();
			foreach (array_intersect_key($SecondaryClasses, $UserInfo['ExtraClasses']) as $PermID => $PermShort) {
				$ClassesDisplay[] = '<span class="tooltip secondary_class" title="'.$Classes[$PermID]['Name'].'">'.$PermShort.'</span>';
			}
			if (!empty($ClassesDisplay)) {
				$Str .= '&nbsp;'.implode('&nbsp;', $ClassesDisplay);
			}
		}

		if ($Class) {
			foreach (array_keys($UserInfo['ExtraClasses']) as $ExtraClass) {
				$Str .= ' ['.Users::make_class_abbrev_string($ExtraClass).']';
			}
			if ($Title) {
				$Str .= ' <strong>('.Users::make_class_string($UserInfo['PermissionID']).')</strong>';
			} else {
				$Str .= ' ('.Users::make_class_string($UserInfo['PermissionID']).')';
			}
		}

		if ($Title) {
			// Image proxy CTs
			if (check_perms('site_proxy_images') && !empty($UserInfo['Title'])) {
				$UserInfo['Title'] = preg_replace_callback('~src=("?)(http.+?)(["\s>])~',
					function($Matches) {
						return 'src=' . $Matches[1] . ImageTools::process($Matches[2]) . $Matches[3];
					},
					$UserInfo['Title']);
			}

			if ($UserInfo['Title']) {
				$Str .= ' <span class="user_title">('.$UserInfo['Title'].')</span>';
			}
		}
		return $Str;
	}

	/**
	 * Given a class ID, return its name.
	 *
	 * @param int $ClassID
	 * @return string name
	 */
	public static function make_class_string($ClassID) {
		global $Classes;
		return $Classes[$ClassID]['Name'];
	}

	public static function make_class_abbrev_string($ClassID) {
		global $Classes;
		return '<acronym title="'.$Classes[$ClassID]['Name'].'">'.$Classes[$ClassID]['Abbreviation'].'</acronym>';
	}

	/**
	 * Returns an array with User Bookmark data: group IDs, collage data, torrent data
	 * @param string|int $UserID
	 * @return array Group IDs, Bookmark Data, Torrent List
	 */
	public static function get_bookmarks($UserID) {
		$UserID = (int)$UserID;

		if (($Data = G::$Cache->get_value("bookmarks_group_ids_$UserID"))) {
			list($GroupIDs, $BookmarkData) = $Data;
		} else {
			$QueryID = G::$DB->get_query_id();
			G::$DB->query("
				SELECT GroupID, Sort, `Time`
				FROM bookmarks_torrents
				WHERE UserID = $UserID
				ORDER BY Sort, `Time` ASC");
			$GroupIDs = G::$DB->collect('GroupID');
			$BookmarkData = G::$DB->to_array('GroupID', MYSQLI_ASSOC);
			G::$DB->set_query_id($QueryID);
			G::$Cache->cache_value("bookmarks_group_ids_$UserID",
				array($GroupIDs, $BookmarkData), 3600);
		}

		$TorrentList = Torrents::get_groups($GroupIDs);

		return array($GroupIDs, $BookmarkData, $TorrentList);
	}

	/**
	 * Generate HTML for a user's avatar or just return the avatar URL
	 * @param unknown $Avatar
	 * @param unknown $UserID
	 * @param unknown $Username
	 * @param unknown $Setting
	 * @param number $Size
	 * @param string $ReturnHTML
	 * @return string
	 */
	public static function show_avatar($Avatar, $UserID, $Username, $Setting, $Size = 150, $ReturnHTML = true) {
		$Avatar = ImageTools::process($Avatar, false, 'avatar', $UserID);
		$Style = 'style="max-height: 400px;"';
		$AvatarMouseOverText = '';
		$SecondAvatar = '';
		$Class = 'class="double_avatar"';
		$EnabledRewards = Donations::get_enabled_rewards($UserID);

		if ($EnabledRewards['HasAvatarMouseOverText']) {
			$Rewards = Donations::get_rewards($UserID);
			$AvatarMouseOverText = $Rewards['AvatarMouseOverText'];
		}
		if (!empty($AvatarMouseOverText)) {
			$AvatarMouseOverText =  "title=\"$AvatarMouseOverText\" alt=\"$AvatarMouseOverText\"";
		} else {
			$AvatarMouseOverText = "alt=\"$Username's avatar\"";
		}
		if ($EnabledRewards['HasSecondAvatar'] && !empty($Rewards['SecondAvatar'])) {
			$SecondAvatar = ' data-gazelle-second-avatar="' . ImageTools::process($Rewards['SecondAvatar'], false, 'avatar2', $UserID) . '"';
		}
		// case 1 is avatars disabled
		switch ($Setting) {
			case 0:
				if (!empty($Avatar)) {
					$ToReturn = ($ReturnHTML ? "<a href=\"user.php?id=$UserID\"><img src=\"$Avatar\" ".($Size?"width=\"$Size\" ":"")."$Style $AvatarMouseOverText$SecondAvatar $Class /></a>" : $Avatar);
				} else {
					$URL = STATIC_SERVER.'common/avatars/default.png';
					$ToReturn = ($ReturnHTML ? "<img src=\"$URL\" width=\"$Size\" $Style $AvatarMouseOverText$SecondAvatar />" : $URL);
				}
				break;
			case 2:
				$ShowAvatar = true;
			case 3:
				switch (G::$LoggedUser['Identicons']) {
					case 0:
						$Type = 'identicon';
						break;
					case 1:
						$Type = 'monsterid';
						break;
					case 2:
						$Type = 'wavatar';
						break;
					case 3:
						$Type = 'retro';
						break;
					case 4:
						$Type = '1';
						$Robot = true;
						break;
					case 5:
						$Type = '2';
						$Robot = true;
						break;
					case 6:
						$Type = '3';
						$Robot = true;
						break;
					default:
						$Type = 'identicon';
				}
				$Rating = 'pg';
				if (!isset($Robot) || !$Robot) {
					$URL = 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($Username)))."?s=$Size&amp;d=$Type&amp;r=$Rating";
				} else {
					$URL = 'https://robohash.org/'.md5($Username)."?set=set$Type&amp;size={$Size}x$Size";
				}
				if ($ShowAvatar == true && !empty($Avatar)) {
					$ToReturn = ($ReturnHTML ? "<img src=\"$Avatar\" width=\"$Size\" $Style $AvatarMouseOverText$SecondAvatar $Class />" : $Avatar);
				} else {
					$ToReturn = ($ReturnHTML ? "<img src=\"$URL\" width=\"$Size\" $Style $AvatarMouseOverText $Class />" : $URL);
				}
				break;
			default:
				$URL = STATIC_SERVER.'common/avatars/default.png';
				$ToReturn = ($ReturnHTML ? "<img src=\"$URL\" width=\"$Size\" $Style $AvatarMouseOverText$SecondAvatar $Class/>" : $URL);
		}
		return $ToReturn;
	}

	public static function has_avatars_enabled() {
		global $HeavyInfo;
		return isset($HeavyInfo['DisableAvatars']) && ($HeavyInfo['DisableAvatars'] != 1);
	}

	/**
	 * Checks whether user has autocomplete enabled
	 *
	 * 0 - Enabled everywhere (default), 1 - Disabled, 2 - Searches only
	 *
	 * @param string $Type the type of the input.
	 * @param boolean $Output echo out HTML
	 * @return boolean
	 */
	public static function has_autocomplete_enabled($Type, $Output = true) {
		$Enabled = false;
		if (empty(G::$LoggedUser['AutoComplete'])) {
			$Enabled = true;
		} elseif (G::$LoggedUser['AutoComplete'] !== 1) {
			switch ($Type) {
				case 'search':
					if (G::$LoggedUser['AutoComplete'] == 2) {
						$Enabled = true;
					}
					break;
				case 'other':
					if (G::$LoggedUser['AutoComplete'] != 2) {
						$Enabled = true;
					}
					break;
			}
		}
		if ($Enabled && $Output) {
			echo ' data-gazelle-autocomplete="true"';
		}
		if (!$Output) {
			// don't return a boolean if you're echoing HTML
			return $Enabled;
		}
	}

  /*
   * Initiate a password reset
   *
   * @param int $UserID The user ID
   * @param string $Username The username
   * @param string $Email The email address
   */
  public static function resetPassword($UserID, $Username, $Email) {
    $ResetKey = Users::make_secret();
    G::$DB->query("
      UPDATE users_info
      SET
        ResetKey = '" . db_string($ResetKey) . "',
        ResetExpires = '" . time_plus(60 * 60) . "'
      WHERE UserID = '$UserID'");
    require(SERVER_ROOT . '/classes/templates.class.php');
    $TPL = NEW TEMPLATE;
    $TPL->open(SERVER_ROOT . '/templates/password_reset.tpl'); // Password reset template
    $TPL->set('Username', $Username);
    $TPL->set('ResetKey', $ResetKey);
    $TPL->set('IP', $_SERVER['REMOTE_ADDR']);
    $TPL->set('SITE_NAME', SITE_NAME);
    $TPL->set('SITE_DOMAIN', SITE_DOMAIN); // TODO: Remove

    Misc::send_email($Email, 'Password reset information for ' . SITE_NAME, $TPL->get(), 'noreply');
  }
}
