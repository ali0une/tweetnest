<?php
		$page = 1;
		// Retrieve favorited tweets
		do {
			// Determine path to Twitter timeline resource
			$path = "1/favorites.json?" . $p . "&count=" . $maxCount . ($maxID ? "&max_id=" . $maxID : "");
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
					// List favorited tweet
					echo l("<li>" . $tweet->id_str . " " . $tweet->created_at . "</li>\n");
					// Create favorited tweet element and add to list
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
			$db->reconnect(); // Sometimes, DB connection times out during favorited tweet loading. This is our counter-action
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

?>
