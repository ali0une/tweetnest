<?php

		global $twitterApi, $db, $config, $access, $search;
		$p = trim($p);
		if(!$twitterApi->validateUserParam($p)){ return false; }
		$maxCount = 200;
		$tweets   = array();
		$sinceID  = 0;
		$maxID    = 0;
		
		echo l("Importing favorites :\n");
		
		// Do we already have tweets?
		$pd = $twitterApi->getUserParam($p);
		if($pd['name'] == "screen_name"){
			$uid        = $twitterApi->getUserId($pd['value']);
			$screenname = $pd['value'];
		} else {
			$uid        = $pd['value'];
			$screenname = $twitterApi->getScreenName($pd['value']);
		}
		$tiQ = $db->query("SELECT `tweetid` FROM `".DTP."tweets` WHERE `favorite` = '1' ORDER BY `id` DESC LIMIT 1");
		if($db->numRows($tiQ) > 0){
			$ti      = $db->fetch($tiQ);
			$sinceID = $ti['tweetid'];
		}
		
		echo l("User ID: " . $uid . "\n");
		
		// Find total number of tweets
		$total = totalTweets($p);
		if($total > 3200){ $total = 3200; } // Due to current Twitter limitation
		$pages = ceil($total / $maxCount);
		
		echo l("Total favorited tweets: <strong>" . $total . "</strong>, Approx. page total: <strong>" . $pages . "</strong>\n");
		if($sinceID){
			echo l("Newest favorited tweet I've got: <strong>" . $sinceID . "</strong>\n");
		}
		
		$page = 1;
		
		// Retrieve favorites tweets
		do {
			// Determine path to Twitter timeline resource
			$path = "favorites/list.json?" . $p . "&count=" . $maxCount .
					($sinceID ? "&since_id=" . $sinceID : "").($maxID ? "&max_id=" . $maxID : "");
#		$path = "1/favorites.json?" . $p . "&count=" . $maxCount . "&since_id=" . $sinceID;
			// Announce
			echo l("Retrieving page <strong>#" . $page . "</strong>: <span class=\"address\">" . ls($path) . "</span>\n");
			// Get data
			$data = $twitterApi->query($path);
			// Drop out on connection error
			if(is_array($data) && $data[0] === false){ dieout(l(bad("Error: " . $data[1] . "/" . $data[2]))); }
			
			// Start parsing
			echo l("<strong>" . ($data ? count($data) : 0) . "</strong> new favorited tweets on this page\n");
			if(!empty($data)){
				echo l("<ul>");
				foreach($data as $i => $tweet){
					// Shield against duplicate tweet from max_id
					if(!IS64BIT && $i == 0 && $maxID == $tweet->id_str){ unset($data[0]); continue; }
					// List tweet
					echo l("<li>" . $tweet->id_str . " " . $tweet->created_at . "</li>\n");
					// Create tweet element and add to list
					$tweets[] = $twitterApi->transformTweet($tweet);
					// Determine new max_id
					$maxID    = $tweet->id_str;
					// Subtracting 1 from max_id to prevent duplicate, but only if we support 64-bit integer handling
					if(IS64BIT){
						$maxID = (int)$tweet->id - 1;
					}
				}
				echo l("</ul>");
			}
			$page++;
		} while(!empty($data));
		
		if(count($tweets) > 0){
			// Ascending sort, oldest first
			$tweets = array_reverse($tweets);
			echo l("<strong>All favorited tweets collected. Reconnecting to DB...</strong>\n");
			$db->reconnect(); // Sometimes, DB connection times out during tweet loading. This is our counter-action
			echo l("Inserting into DB...\n");
			$error = false;
			foreach($tweets as $tweet){
				$q = $db->query($twitterApi->insertQuery($tweet));
				if(!$q){
					dieout(l(bad("DATABASE ERROR: " . $db->error())));
				}
				$text = $tweet['text'];
				$te   = $tweet['extra'];
				if(is_string($te)){ $te = @unserialize($tweet['extra']); }
				if(is_array($te)){
					// Because retweets might get cut off otherwise
					$text = (array_key_exists("rt", $te) && !empty($te['rt']) && !empty($te['rt']['screenname']) && !empty($te['rt']['text']))
						? "RT @" . $te['rt']['screenname'] . ": " . $te['rt']['text']
						: $tweet['text'];
				}
				$search->index($db->insertID(), $text);
			}

			echo !$error ? l(good("Done!\n")) : "";
		} else {
			echo l(bad("Nothing to insert.\n"));
		}

		// Checking favorites -- scanning all
		echo l("\n<strong>Syncing favourites...</strong>\n");
		// Resetting these
		$favs  = array(); $maxID = 0; $sinceID = 0; $page = 1;
		do {
			$path = "favorites/list.json?" . $p . "&count=" . $maxCount . ($maxID ? "&max_id=" . $maxID : "");
			echo l("Retrieving page <strong>#" . $page . "</strong>: <span class=\"address\">" . ls($path) . "</span>\n");
			$data = $twitterApi->query($path);
			if(is_array($data) && $data[0] === false){ dieout(l(bad("Error: " . $data[1] . "/" . $data[2]))); }
			echo l("<strong>" . ($data ? count($data) : 0) . "</strong> total favorite tweets on this page\n");
			if(!empty($data)){
				echo l("<ul>");
				foreach($data as $i => $tweet){
					if(!IS64BIT && $i == 0 && $maxID == $tweet->id_str){ unset($data[0]); continue; }
#					if($tweet->user->id_str != $uid){
						echo l("<li>" . $tweet->id_str . " " . $tweet->created_at . "</li>\n");
						$favs[] = $tweet->id_str;
#					}
					$maxID = $tweet->id_str;
					if(IS64BIT){
						$maxID = (int)$tweet->id - 1;
					}
				}
				echo l("</ul>");
			}
			echo l("<strong>" . count($favs) . "</strong> favorited tweets so far\n");
			$page++;
		} while(!empty($data));
		
		// Blank all favorites
		$db->query("UPDATE `".DTP."tweets` SET `favorite` = '0'");
		// Insert favorites into DB
		$db->query("UPDATE `".DTP."tweets` SET `favorite` = '1' WHERE `tweetid` IN ('" . implode("', '", $favs) . "')");
		echo l(good("Updated favorites!"));

?>
