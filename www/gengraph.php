<?php
/*
 * $Id$
 *
 * written by Manuel Kasper <mk@neon1.net> for Monzoon Networks AG
 */

require_once('func.inc');

$asns = array();
if (isset($_GET['asset'])) {
	$asset = strtoupper($_GET['asset']);
	$aslist = getASSET($asset);
	if ($aslist[0]) {
		foreach ($aslist as $as) {
                	$as_tmp = substr($as, 2);
			if (is_numeric($as_tmp) && $as_tmp > 0) {
				$asns[] = $as_tmp;
			}
		}
	}
} else if (isset($_GET['as'])) {
	$asns = explode(',', $_GET['as']);
} else {
	die('No AS Given.');
}

foreach ($asns as $asn)
	if (!preg_match("/^[0-9a-zA-Z]+$/", $asn))
		die("Invalid AS (".$asn.")");


$width = $default_graph_width;
$height = $default_graph_height;
if (isset($_GET['width']))
	$width = (int)$_GET['width'];
if (isset($_GET['height']))
	$height = (int)$_GET['height'];
$v6_el = "";
if (@$_GET['v'] == 6)
	$v6_el = "v6_";

if(isset($_GET['peerusage']) && $_GET['peerusage'] == '1')
	$peerusage = 1;
else
	$peerusage = 0;

$knownlinks = getknownlinks();

if ($compat_rrdtool12) {
	/* cannot use full-size-mode - must estimate height/width */
	$height -= 65;
	$width -= 81;
	if ($vertical_label)
		$width -= 16;
}

$data = "graph - " .
	"--slope-mode --alt-autoscale -u 0 -l 0 --imgformat=PNG --base=1000 --height=$height --width=$width " .
	"--color BACK#ffffff00 --color SHADEA#ffffff00 --color SHADEB#ffffff00 ";

if (!$compat_rrdtool12)
	$data .= "--full-size-mode ";

if ($vertical_label) {
	if($outispositive)
		$data .= "--vertical-label '<- IN | OUT ->' ";
	else
		$data .= "--vertical-label '<- OUT | IN ->' ";
}

if($showtitledetail && @$_GET['dname'] != "")
	$data .= "--title " . escapeshellarg($_GET['dname']) . " ";
else
	if (isset($_GET['v']) && is_numeric($_GET['v']))
		$data .= "--title IPv" . $_GET['v'] . " ";

if (isset($_GET['nolegend']))
	$data .= "--no-legend ";

if (isset($_GET['start']) && is_numeric($_GET['start']))
	$data .= "--start " . $_GET['start'] . " ";

if (isset($_GET['end']) && is_numeric($_GET['end']))
	$data .= "--end " . $_GET['end'] . " ";

$instack = '';
$outstack = '';
$tot_in_bits = "CDEF:tot_in_bits=0";
$tot_out_bits = "CDEF:tot_out_bits=0";
$firstLegend = true;
$inArea = $outArea = $count = 0;

foreach ($asns as $as) {
	$rrdfile = getRRDFileForAS($as, $peerusage);
	if (!file_exists($rrdfile)) { continue; }
	$count++;

	$inDEF = array();
	$outDEF = array();

	foreach ($knownlinks as $link) {
		$inDEF[$link['tag'] . '_' . $v6_el] = "l" . crc32("{$link['tag']}_{$v6_el}_{$as}_in");
		$outDEF[$link['tag'] . '_' . $v6_el] = "l" . crc32("{$link['tag']}_{$v6_el}_{$as}_out");
	}

	/* geneate RRD DEFs */
	foreach ($knownlinks as $link) {
		$data .= "DEF:{$inDEF[$link['tag'] . '_' . $v6_el]}=\"$rrdfile\":{$link['tag']}_{$v6_el}in:AVERAGE ";
		$data .= "DEF:{$outDEF[$link['tag'] . '_' . $v6_el]}=\"$rrdfile\":{$link['tag']}_{$v6_el}out:AVERAGE ";
	}

	if ($compat_rrdtool12) {
		/* generate a CDEF for each DEF to multiply by 8 (bytes to bits), and reverse for outbound */
		foreach ($knownlinks as $link) {
		   if ($outispositive) {
				$data .= "CDEF:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits={$inDEF[$link['tag'] . '_' . $v6_el]},-8,* ";
				$data .= "CDEF:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits={$outDEF[$link['tag'] . '_' . $v6_el]},8,* ";
			} else {
				$data .= "CDEF:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits={$inDEF[$link['tag'] . '_' . $v6_el]},8,* ";
				$data .= "CDEF:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits={$outDEF[$link['tag'] . '_' . $v6_el]},-8,* ";
			}
		}
	} else {
		/* generate a CDEF for each DEF to multiply by 8 (bytes to bits), and reverse for outbound */
		foreach ($knownlinks as $link) {
			$data .= "CDEF:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits_pos={$inDEF[$link['tag'] . '_' . $v6_el]},8,* ";
			$data .= "CDEF:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits_pos={$outDEF[$link['tag'] . '_' . $v6_el]},8,* ";
			$tot_in_bits .= ",{$inDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,ADDNAN";
			$tot_out_bits .= ",{$outDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,ADDNAN";
		}

		foreach ($knownlinks as $link) {
			if ($outispositive) {
				$data .= "CDEF:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits={$inDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,-1,* ";
				$data .= "CDEF:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits={$outDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,1,* ";
			} else {
				$data .= "CDEF:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits={$outDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,-1,* ";
				$data .= "CDEF:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits={$inDEF[$link['tag'] . '_' . $v6_el]}_bits_pos,1,* ";
			}
		}
	}

	/* generate graph area/stack for inbound */
	foreach ($knownlinks as $link) {
		if ($outispositive && $brighten_negative)
			$col = $link['color'] . "BB";
		else
			$col = $link['color'];
		if ($firstLegend) {
			$descr = str_replace(':', '\:', $link['descr']); # Escaping colons in description
		} else {
			$descr = '';
		}
		$instack .= "AREA:{$inDEF[$link['tag'] . '_' . $v6_el]}_bits#{$col}:\"{$descr}\"";
		if ($inArea++ > 0)
			$instack .= ":STACK";
		$instack .= " ";
	}
	$firstLegend = false;

	/* generate graph area/stack for outbound */
	foreach ($knownlinks as $link) {
		if ($outispositive || !$brighten_negative)
			$col = $link['color'];
		else
			$col = $link['color'] . "BB";
		$outstack .= "AREA:{$outDEF[$link['tag'] . '_' . $v6_el]}_bits#{$col}:";
		if ($outArea++ > 0)
			$outstack .= ":STACK";
		$outstack .= " ";
	}
}

if ($count > 0) {
	$data .= "$tot_in_bits ";
	$data .= "$tot_out_bits ";

	$data .= "VDEF:tot_in_bits_95th_pos=tot_in_bits,95,PERCENT ";
	$data .= "VDEF:tot_out_bits_95th_pos=tot_out_bits,95,PERCENT ";

	if ($outispositive) {
	        $data .= "CDEF:tot_in_bits_95th=tot_in_bits,POP,tot_in_bits_95th_pos,-1,* ";
	        $data .= "CDEF:tot_out_bits_95th=tot_out_bits,POP,tot_out_bits_95th_pos,1,* ";
	} else {
	        $data .= "CDEF:tot_in_bits_95th=tot_in_bits,POP,tot_in_bits_95th_pos,1,* ";
	        $data .= "CDEF:tot_out_bits_95th=tot_out_bits,POP,tot_out_bits_95th_pos,-1,* ";
	}

	$data .= $instack;
	$data .= $outstack;

	if ($show95th && !$compat_rrdtool12) {
		$data .= "LINE1:tot_in_bits_95th#FF0000 ";
		$data .= "LINE1:tot_out_bits_95th#FF0000 ";
		$data .= "GPRINT:tot_in_bits_95th_pos:'95th in %6.2lf%s' ";
		$data .= "GPRINT:tot_out_bits_95th_pos:'95th out %6.2lf%s' ";
	}
}

# zero line
$data .= "HRULE:0#00000080";

$descriptorspec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($rrdtool . ' - ', $descriptorspec, $pipes);
fwrite($pipes[0], $data);
fclose($pipes[0]);
stream_set_timeout($pipes[1], 1);
$stdout = stream_get_contents($pipes[1]);
stream_set_timeout($pipes[2], 1);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

header("Content-Type: image/png");
echo $stdout;

exit;

?>
