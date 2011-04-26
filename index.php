<?php

require_once('eregansu/lib/common.php');

uses('rdf');

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
	foreach($triples->triples as $k => $subject)
	{
		$nodes[$k] = new BNode($k, $subject);
	}
//	echo '<pre>';
//	print_r(array_keys($nodes));
	$seen = array();
	$replace = array();
	foreach($nodes as $node)
	{
		$loop = array();
		if(!strncmp($node->subject, '_:', 2) || !strncmp($node->subject, '#', 1))
		{
//			$result[] = 'Resolving node ' . $node->subject;
			$hash = $node->resolve($seen, $triples, $nodes, 1, $loop);
			$subj = '_:' . str_replace(array('{', '}'), array('', ''), $hash);
			$replace[$node->subject] = $subj;
		}
	}
//	print_r(array_keys($nodes));
//	$result[] = "Replacing subjects";
//	print_r($replace);
	foreach($replace as $was => $now)
	{
		foreach($nodes as $node)
		{
			if(!strcmp($node->subject, $was))
			{
//				$result[] = "Replacing " . $node->subject . " with " . $now;
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
//	$result[] = "Final pass";
	foreach($nodes as $node)
	{
		if(!isset($node->hashValue))
		{			
//			$result[] = 'Resolving node ' . $node->subject;
			$loop = array();
			$node->resolve($seen, $triples, $nodes, 1, $loop);
		}
		$subjects[$node->subject] = $node;
	}
	ksort($subjects);
//	$result[] = "Result:";
	foreach($subjects as $subj)
	{
		foreach($subj->triples as $trip)
		{
			$result[] = '<' . $subj->subject . '> <' . $trip->predicate . '> {' . strval($trip->object) . '}';
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

	public function __construct($subject, $triples)
	{
		$this->subject = strval($subject);
		$this->triples = $triples;
		$this->hashValue = null;
	}
	
	public function resolve(&$seen, $triples, $bnodes, $depth = 1, &$loop)
	{
		$seen[] = $this;
		$loop[] = $this;
		$values = array();
		foreach($this->triples as $triple)
		{
			if($depth == 1)
			{
				$loop = array();
			}
			if(!strcmp($triple->predicate, RDF::rdf.'ID') || !strcmp($triple->predicate, RDF::rdf.'about')) continue;
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
			$values[$triple->hashValue] = $triple;
		}
		ksort($values);
		$this->triples = array_values($values);
		$this->hashValue = '{' . sha1(implode("\n", array_keys($values))) . '}';
		return $this->hashValue;
	}

	public function resolveObject($object, &$seen, $triples, $bnodes, $depth, &$loop)
	{
		global $result;

		if(!strncmp($object, '_:', 2) || !strncmp($object, '#', 1))
		{
			/* Recurse */
			@$node = $bnodes[strval($object)];
			if($node === null)
			{
//				$result[] = 'Found dangling ref to ' . strval($object) . ' from ' . strval($this->subject) . ' at depth ' . $depth;
				return '<!dangling!>';
			}
			if(in_array($node, $loop))
			{
//				$result[] = 'Found loop while resolving ' . strval($object) . ' from ' . strval($this->subject) . ' at depth ' . $depth;
//				$result[] = implode(', ', $loop);
				return '<!loop!>';
			}
//			$result[] = 'Recursing into ' . strval($node) . ' from '. strval($this->subject) . ' at depth ' . $depth;
			return '#' . $node->resolve($seen, $triples, $bnodes, $depth + 1, $loop);
		}
		else
		{
			return '<' . strval($object) . '>';
		}
	}

	public function __toString()
	{
		return strval($this->subject);
	}
}

require_once('form.phtml');
