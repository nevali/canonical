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
	$triples = RDF::tripleSetFromXMLString($_POST['rdf']);
	$subjects = array();
	$bnodes = array();
	foreach($triples->triples as $k => $subject)
	{
		if(!strncmp($k, '_:', 2))
		{
			$bnodes[$k] = new BNode($subject);
		}
	}
	echo '<pre>';
	$seen = array();
	foreach($bnodes as $bnode)
	{	   
		$loop = array();
		$hash = $bnode->resolve($seen, $triples, $bnodes, 1, $loop);
		$subjects[$hash] = $bnode;
	}
	print_r($subjects);
//	print_r($triples);
//	print_r($bnodes);
	echo '</pre>';
}

class BNode
{
	public $triples;
	public $hashValue;

	public function __construct($triples)
	{
		$this->triples = $triples;
		$this->hashValue = null;
	}
	
	public function resolve(&$seen, $triples, $bnodes, $depth = 1, &$loop)
	{
		if(isset($this->hashValue))
		{
			return $this->hashValue;
		}
		$seen[] = $this;
		$loop[] = $this;
		foreach($this->triples as $triple)
		{
			if($triple->object instanceof RDFURI)
			{
				$triple->hashValue = '<' . $triple->predicate . '> ' . $this->resolveObject($triple->object, $seen, $triples, $bnodes, $depth);
			}
		}
		$values = array();
		foreach($this->triples as $triple)
		{
			$values[$triple->hashValue] = $triple;
		}
		ksort($values);
		$this->triples = array_values($values);
		$this->hashValue = '{' . sha1(implode("\n", array_keys($values))) . '}';
		return $this->hashValue;
	}

	public function resolveObject($object, &$seen, $triples, $bnodes, $depth, &$loop)
	{
		if(strncmp($object, '_:', 2))
		{
			/* Recurse */
			@$node = $bnodes[strval($object)];
			if($node === null)
			{
				return '<!dangling!>';
			}
			if(in_array($node, $loop))
			{
				return '#' . $depth;
			}
			return '#' . $node->resolve($seen, $triples, $bnodes, $depth + 1, $loop);
		}
		else
		{
			return '<' . strval($object) . '>';
		}
	}
}

require_once('form.phtml');
