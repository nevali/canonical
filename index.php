<?php

require_once('eregansu/lib/common.php');

set_time_limit(0);

uses('rdf');

function emit($str)
{
	if(!empty($_REQUEST['debug']))
	{
		echo "<pre>" . _e($str) . "</pre>";
		flush();
	}
}

$result = null;

if(get_magic_quotes_gpc())
{
	$_POST['rdf'] = @stripslashes($_POST['rdf']);
}

if(isset($_POST['rdf']) && strlen($_POST['rdf']))
{
	$result = array();
	$triples = RDF::tripleSetFromXMLString($_POST['rdf']);
	$subjects = array();
	$nodes = array();
	$result[] = "Source:";	
	foreach($triples->triples as $subj => $trips)
	{
		foreach($trips as $trip)
		{
			if($trip->predicate == RDF::rdf.'about' || $trip->predicate == RDF::rdf.'ID' || $trip->predicate == RDF::rdf.'nodeID')
			{
				continue;
			}
			$result[] = '<' . $subj . '> <' . $trip->predicate . '> {' . strval($trip->object) . '}';
		}
	}
	emit('Processing...');
	foreach($triples->triples as $k => $subject)
	{
		$nodes[$k] = new BNode($k, $subject);
	}
//	echo '<pre>';
//	print_r(array_keys($nodes));
	$seen = array();
	$replace = array();
	$counter = 0;
	foreach($nodes as $node)
	{
		$loop = array();
		if($node->bNode)
		{
			emit('Resolving node ' . $node->subject);
			$hash = $node->resolve($seen, $triples, $nodes, 1, $loop);
			$subj = '_:' . str_replace(array('{', '}'), array('', ''), $hash);
			$replace[$node->subject] = $subj;
		}
		$counter++;
	}
	if(!empty($_REQUEST['debug']))
	{
//		echo '<pre>'; print_r(array_keys($nodes)); echo '</pre>';
		emit("Replacing subjects:");
	}
//	print_r($replace);
	foreach($replace as $was => $now)
	{
		$ndlist = array();
		foreach($nodes as $node)
		{
			foreach($node->triples as $triple)
			{
				if($triple->object instanceof RDFURI && !strcmp($triple->object, $was))
				{
					$ndlist[] = $node->nonDeterministicHash . ' ' . $triple->predicate;
				}
			}
		}
		sort($ndlist);
		emit('Referencing nodes: ' . implode(", ", $ndlist));
		$now = '_:' . sha1(substr($now, 2) . "\n" . implode("\n", $ndlist));
		foreach($nodes as $node)
		{
			if(!strcmp($node->subject, $was))
			{
//				$result[] = "Replacing " . $node->subject . " with " . $now;
				$node->was = strval($node->subject);
				$node->subject = $now;
			}
			foreach($node->triples as $k => $triple)
			{
				if(!strcmp($triple->subject, $was))
				{
					$triple->subject = $now;
				}
				if($triple->object instanceof RDFURI && !strcmp($triple->object, $was))
				{
//					$result[] = "Updating object from " . $triple->object . " to " . $now;
					$triple->object->value = $now;
				}
				$node->triples[$k] = $triple;
			}
		}
	}
//	print_r(array_keys($nodes));
	emit("Final pass");
	$c = 0;
	foreach($nodes as $node)
	{
		if(!isset($node->hashValue))
		{			
//			$result[] = 'Resolving node ' . $node->subject;
			$loop = array();
			$node->resolve($seen, $triples, $nodes, 1, $loop);
		}
		$subjects[strval($node->subject) . ' ' . $c] = $node;
		$c++;
	}
	ksort($subjects);
	$result[] = "Result:";
	foreach($subjects as $subj)
	{
		$result[] = (isset($subj->was) ? '[was ' . $subj->was . ']' : '<' . $subj->subject . '>') . ': ' . count($subj->triples) . ' triples:';
		foreach($subj->triples as $trip)
		{
			$result[] = (isset($subj->was) ? '[was ' . $subj->was . '] ' : '') . '<' . $subj->subject . '> <' . $trip->predicate . '> {' . strval($trip->object) . '}';
		}
	}
//	print_r($triples);
//	print_r($bnodes);
	echo '</pre>';
}

class BNode
{
	public $subject;
	public $triples;
	public $hashValue;
	public $nonDeterministicHash;
	public $bNode;
	public $objectHashes = array();

	public function __construct($subject, $triples)
	{
		$this->subject = strval($subject);
		$this->triples = $triples;
		$this->hashValue = null;
		$hval = array();
		if(!strncmp($this->subject, '_:', 2) || !strncmp($this->subject, '#', 1))
		{
			$hval[] = '#';
			$this->bNode = true;
		}
		else
		{
			$hval[] = strval($this->subject);
		}
		foreach($this->triples as $trip)
		{
			$hval[] = $trip->predicate;
			if(!strncmp($trip->object, '_:', 2) || !strncmp($trip->object, '#', 1))
			{
				$hval[] = '#';
			}
			else
			{
				$hval[] = strval($trip->object);
			}
		}
		$this->nonDeterministicHash = sha1(implode("\n", $hval));
	}
	
	public function resolve(&$seen, $triples, $bnodes, $depth = 1, &$loop)
	{
		global $result;
		
		$seen[] = $this;
		$values = array();
		$c = 0;
		$hashes = array();
		emit(str_repeat('+', $depth) . ' Resolving ' . $this->subject);
		foreach($this->triples as $triple)
		{
			if($depth == 1)
			{
				$loop = array($this);
			}
			if(!strcmp($triple->predicate, RDF::rdf.'ID') || !strcmp($triple->predicate, RDF::rdf.'about') || !strcmp($triple->predicate, RDF::rdf.'nodeID')) continue;
			if($triple->object instanceof RDFURI)
			{
				$hashValue = $this->resolveObject($triple->object, $seen, $triples, $bnodes, $depth, $loop);
			}
			else if($triple->object instanceof RDFComplexLiteral)
			{
				if(isset($triple->object->{RDF::xml.' lang'}))
				{
					$hashValue = '"""' . $triple->object->value . '"""@' . $triple->object->{RDF::xml.' lang'}[0];
				}
				else if(isset($triple->object->{RDF::rdf.'datatype'}))
				{
					$hashValue = '"""' . $triple->object->value . '"""^^' . $triple->object->{RDF::rdf.'datatype'}[0];
				}
				else
				{
					$hashValue = '"""' . $triple->object->value . '"""';
				}
			}
			else
			{
				$hashValue = '""""' . strval($triple->object) . '""""';
			}
			$triple->hashValue = '<' . $triple->predicate . '> ' . $hashValue;
			$values[$triple->hashValue . ' ' . $c] = $triple;
			$c++;
			$hashes[] = $triple->hashValue;
		}
		ksort($values);
		sort($hashes);
		$this->triples = array_values($values);
		emit(str_repeat('-', $depth) . ' ' . implode(', ' , $hashes));
		$this->hashValue = '{' . sha1(implode("\n", $hashes)) . '}';
		return $this->hashValue;
	}

	public function resolveObject($object, &$seen, $triples, $bnodes, $depth, &$loop)
	{
		global $result;
		
		$obj = strval($object);
		if(!isset($this->objectHashes[$obj]))
		{
			if(!strncmp($obj, '_:', 2) || !strncmp($obj, '#', 1))
			{
				/* Recurse */
				@$node = $bnodes[$obj];
				if($node === null)
				{
//				$result[] = 'Found dangling ref to ' . strval($object) . ' from ' . strval($this->subject) . ' at depth ' . $depth;
					$this->objectHashes[$obj] = '<!dangling!>';
				}
				else if(in_array($node, $loop))
				{
					emit('Found loop while resolving ' . strval($object) . ' from ' . strval($this->subject) . ' at depth ' . $depth);
					emit(implode(', ', $loop));
					$this->objectHashes[$obj] = '<!loop!>';
				}
				else
				{
//			$result[] = 'Recursing into ' . strval($node) . ' from '. strval($this->subject) . ' at depth ' . $depth;
					$loop[] = $node;
					$this->objectHashes[$obj] = '#' . $node->resolve($seen, $triples, $bnodes, $depth + 1, $loop);
					array_shift($loop);					
				}
			}
			else
			{
				$this->objectHashes[$obj] = '<' . $obj . '>';
			}
		}
		return $this->objectHashes[$obj];
	}

	public function __toString()
	{
		return strval($this->subject);
	}
}

require_once('form.phtml');
