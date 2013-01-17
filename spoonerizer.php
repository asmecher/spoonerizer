<?php

/**
 * Spoonerizer
 * Copyright (c) 2013 by Alec Smecher
 *
 * Generate spoonerisms.
 * Spoonerizer has two modes of operation:
 * 1) Input taken from standard input and spoonerisms injected inline.
 *    To operate in this mode, pipe input into this script and output to e.g.
 *    a text file.
 *
 * 2) Input taken from the command line and spoonerism options enumerated
 *    exhaustively. To operate in this mode, invoke with options:
 *
 *    php spoonerizer.php -m word1 word2 ...
 *
 * Spoonerizer uses the Festival speech synthesizer to turn words into a series
 * of segments representing their pronunciations. (These are cached in the
 * spoonerizer.cache file, so if you have that cache, festival itself is not
 * required, though you'll have to disable the code below that runs it.)
 *
 * Spoonerism candidates are identified by switching one or several of the
 * segments in one word for one or several in the other. If both new candidate
 * words are in the dictionary, the spoonerism is acceptable.
 *
 * In mode #1, words a and b in "a b" can be spoonerized, or words a and c in
 * "a b c". In the latter case word b is left untouched.
 *
 * This is a messy hack.
 *
 * Distributed under the GNU GPL v2. For full terms see the file COPYING.
 */

define('DICTIONARY_PATH', '/usr/share/dict/american-english');
define('FESTIVAL_BIN', 'festival/bin/festival');

$cmdname = array_shift($argv);
$option = array_shift($argv);

// Open festival for word-to-segment conversion
$festival = proc_open(
	FESTIVAL_BIN,
	array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => STDERR
	),
	$pipes, getcwd(), array()
);
if (!is_resource($festival)) die('Could not open festival.');
stream_set_blocking($pipes[0], 0);

/**
 * Get the segments from a piece of text using Festival.
 * @param $pipes array The pipes structure used to open a festival process
 * @param $text string The text to synthesize
 * @return array ("segment1", "segment2", "segment3", ...)
 */
function getSegments($pipes, $text) {
	// Festival sometimes doesn't like unpronounceables.
	if (!preg_match('/[a-zA-Z]/', $text)) return array();

	fprintf($pipes[0], '(utt.save (utt.synth (Utterance Text "%s")) "-")' . "\n", str_replace('"', ' ', $text));
	$segments = array();
	do {
		$line = fgets($pipes[1]);
		if (preg_match('/name ([^;]+) ; dur_factor /', $line, $matches)) {
			$segments[] = $matches[1];
		}
	} while (trim($line) != 'End_of_Stream_Items');

	fflush($pipes[1]);

	// Remove the initial and final pause segments
	array_shift($segments);
	array_pop($segments);

	return $segments;
}

$stderr = fopen('php://stderr', 'w');

// Get segments for all words, cached if available.
if (file_exists('spoonerizer.cache')) {
	fputs($stderr, 'Loading cache... ');
	$words = unserialize(file_get_contents('spoonerizer.cache'));
	fputs($stderr, "Done.\n");
} else {
	$words = array();
}

fputs($stderr, 'Calculating segments');
$dictionary = array_map('trim', file(DICTIONARY_PATH));
$i=0;
$cacheInvalid = false;
foreach ($dictionary as $word) {
	if (!empty($word) && !isset($words[$word])) {
		$words[$word] = getSegments($pipes, $word);
		$cacheInvalid = true;
	}
	if (($i++ % 500) == 0) fputs($stderr, '.');
}
fputs($stderr, " Done.\n");

// Save cache
if ($cacheInvalid) {
	fputs($stderr, 'Saving cache... ');
	file_put_contents('spoonerizer.cache', serialize($words));
	fputs($stderr, "Done.\n");
}

// The $words array now contains a complete mapping of all word-to-segment
// mappings, i.e. array("word" => array("w", "er", "d"), ...)

// Calculate a couple of helper arrays to speed things up
$wordsCollapsed = array();
foreach ($words as $word => $segments) {
	$wordsCollapsed[$word] = implode(' ', $segments);
}
$wordsCollapsedInverted = array_flip($wordsCollapsed);

/**
 * Determine any available spoonerisms of the given segment sets.
 * @param $segments1 array("w", "er", "d")
 * @param $segments2 array("w", "er", "d")
 * @param $words array The main word table
 * @param $wordsCollapsedInverted segment-to-word mapping as strings for speed
 */
function spoonerize($segments1, $segments2, $words, $wordsCollapsedInverted) {
	foreach (array(
		array(1, 1), // 1 segment from the first word, 1 from second
		array(1, 2), // 2 segments from the first word, 1 from second
		array(2, 1), // 1 segment from the first word, 2 from second
		array(2, 2), // 2 segments from each word
	) as $pattern) {
		// Make sure there are enough syllables to leave something over
		if (count($segments1) <= $pattern[1] + 1 || count($segments2) <= $pattern[0] + 1 || $segments1 == $segments2) continue;

		// Make sure the initial syllables aren't the same
		if ($pattern[0] == $pattern[1] && array_slice($segments1, 0, $pattern[0]) == array_slice($segments2, 0, $pattern[0])) continue;

		// Invent some words for testing
		$hypothetical1 = array_merge(
			array_slice($segments2, 0, $pattern[0]),
			array_slice($segments1, $pattern[1])
		);
		$hypothetical2 = array_merge(
			array_slice($segments1, 0, $pattern[1]),
			array_slice($segments2, $pattern[0])
		);
		$hypothetical1Collapsed = implode(' ', $hypothetical1);
		$hypothetical2Collapsed = implode(' ', $hypothetical2);
		if (	isset($wordsCollapsedInverted[$hypothetical1Collapsed]) &&
			isset($wordsCollapsedInverted[$hypothetical2Collapsed])) {
			return array($hypothetical1Collapsed, $hypothetical2Collapsed);
		}
	}
	// If we got here, no spoonerisms could be found.
	return false;
}

if (empty($option)) {
	$contents = file_get_contents('php://stdin');

	// Dash for range of numbers
	$contents = preg_replace('/([0-9]+)-([0-9]+)/', '\1 to \2', $contents);

	// Thousand separators for pronouncing numbers
	$contents = preg_replace('/([0-9]+),([0-9]+)/', '\1\2', $contents);

	// Decimal separators for pronouncing numbers
	$contents = preg_replace('/([0-9]+)\.([0-9]+)/', '\1 point \2', $contents);

	// Spell out numbers
	function replacer($input) {
		$nf = new NumberFormatter('en_US', NumberFormatter::SPELLOUT);
		return ' ' . $nf->format($input[0]) . ' ';
	}
	$contents = preg_replace_callback('/[0-9]+/', 'replacer', $contents);

	// To separate punctuation from words, insert some spaces.
	// Would be much better to use preg_callback to match words in place.
	$contents = str_replace(
		array('.', ',', '-', ':', '!', ';'),
		array(' . ', ' , ', ' - ', ' : ', ' ! ', ' ; '),
		$contents
	);

	fputs($stderr, "Calculating extra segments for input text... ");
	$contentsArray = preg_split('/[ \n]/', $contents);
	foreach ($contentsArray as $word) {
		if (!empty($word) && !isset($words[$word])) {
			$words[$word] = getSegments($pipes, $word);
		}
	}
	fputs($stderr, "Done.\n");

	for ($i=0; $i<count($contentsArray)-3; $i++) {
		$segments1 = @$words[$contentsArray[$i]];
		$segments2 = @$words[$contentsArray[$i+1]];
		$segments3 = @$words[$contentsArray[$i+2]];
		if ($results = spoonerize($segments1, $segments2, $words, $wordsCollapsedInverted)) {
			// Adjacent words
			$words1 = array_keys($wordsCollapsed, $results[0]);
			$words2 = array_keys($wordsCollapsed, $results[1]);
			echo '{"' . $words1[0] . ' ' . $words2[0] . '" (' . $contentsArray[$i] . ' ' . $contentsArray[$i+1] . ')} ';
			$i++;
		} elseif ($results = spoonerize($segments1, $segments3, $words, $wordsCollapsedInverted)) {
			// Skip one word
			$words1 = array_keys($wordsCollapsed, $results[0]);
			$words2 = array_keys($wordsCollapsed, $results[1]);
			echo '{"' . $words1[0] . ' ' . $contentsArray[$i+1] . ' ' . $words2[0] . '" (' . $contentsArray[$i] . ' ' . $contentsArray[$i+1] . ' ' . $contentsArray[$i+2] . ')} ';
			$i+=2;
		} else {
			echo $contentsArray[$i] . ' ';
		}
	}

	while ($i < count($contentsArray)) {
		echo $contentsArray[$i++] . ' ';
	}

	echo "\n";

} elseif ($option == '-m') {
	fputs($stderr, "Spoonerizing from arguments.\n");
	foreach ($argv as $spoonerizeThis) {
		fputs($stderr, "Spoonerizing $spoonerizeThis");
		echo "$spoonerizeThis: ";
		$theseSegments = getSegments($pipes, $spoonerizeThis);
		$i=0;
		foreach ($words as $word => $segments) {
			if ($results = spoonerize($theseSegments, $segments, $words, $wordsCollapsedInverted)) {
				$matches[$word] = array_keys($wordsCollapsed, $results[0]);
				echo "\t$spoonerizeThis $word: ";
				foreach (array_keys($wordsCollapsed, $results[0]) as $word1) {
					echo $word1 . ' ';
				}
				echo ' / ';
				foreach (array_keys($wordsCollapsed, $results[1]) as $word2) {
					echo $word2 . ' ';
				}
				echo "\n";
			}
			if ($i++ % 1000 == 0) fputs($stderr, '.');
		}
		echo "\n";
		fputs($stderr, " Done.\n");
	}
}

?>
