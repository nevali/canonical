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

function emit_r($thing)
{
	ob_start();
	print_r($thing);
	emit(ob_get_clean());
}

function stringify($thing)
{
	if($thing instanceof RDFURI)
	{
		$u = strval($thing);
		if(!strncmp($u, '#', 1))
		{
			return '_:' . substr($u, 1);
		}
		else if(!strncmp($u, '_:', 2))
		{
			return $u;
		}
		else
		{
			return '<' . $u . '>';
		}
	}
	if($thing instanceof RDFComplexLiteral)
	{				
		if(isset($thing->{RDF::xml.' lang'}[0]))
		{
			return '"""' . $thing->value . '"""@' . $thing->{RDF::xml.' lang'}[0];
		}
		else if(isset($thing->{RDF::rdf.'datatype'}[0]))
		{
			return '"""' . $thing->value . '"""^^' . $thing->{RDF::rdf.'datatype'}[0];
		}
		else
		{
			return '"""' . $thing->value . '"""';
		}
	}
	else
	{
		return '"""' . strval($thing) . '"""';
	}
}

function dumptriples($triples, $title = '')
{
	global $result;

	if(strlen($title))
	{
		$result[] = $title;
	}
	if(is_object($triples))
	{
		$triples = $triples->triples;
	}
	foreach($triples as $subj => $trips)
	{
		if($trips instanceof BNode)
		{
			if(!empty($_REQUEST['debug']))
			{
				$result[] = '## Subject: ' . $trips->subject . (isset($trips->was) ? ' was ' . $trips->was : '');
				if(strlen($trips->nonDeterministicHash))
				{
					$result[] = '## NDhash: ' . $trips->nonDeterministicHash;
				}
				if(count($trips->inbound))
				{
					$result[] = '## Inbound: ' . implode(', ', $trips->inbound);
				}
				if(strlen($trips->hashValue))
				{
					$result[] = '## Hash value: ' . $trips->hashValue;
				}
			}
			$trips = $trips->triples;
		}
		foreach($trips as $trip)
		{
			if($trip->predicate == RDF::rdf.'about' || $trip->predicate == RDF::rdf.'ID' || $trip->predicate == RDF::rdf.'nodeID')
			{
				continue;
			}
			$result[] = stringify($trip->subject) . ' <' . $trip->predicate . '> ' . stringify($trip->object) . ' . ' . (isset($trip->canonicalObjectValue) ? '#=> '. $trip->canonicalObjectValue : '');
		}
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
	dumpTriples($triples, 'Source:');
	emit('Processing...');
	$hasher = new GraphHasher($triples);
	$hasher->markInbound();
	$hasher->updateHashes();
	$hasher->replace();
	$hasher->sort();
	$hasher->dump('Result:');
}

class GraphHasher
{
	public $nodes = array();
	public $replacements = array();

	public function __construct($triples)
	{
		if(is_object($triples))
		{
			$triples = $triples->triples;
		}
		foreach($triples as $k => $subject)
		{
			$this->nodes[$k] = new BNode($k, $subject);
		}
	}

	public function dump($title = '')
	{
		dumpTriples($this->nodes, $title);
	}

	public function markInbound()
	{
		foreach($this->nodes as $node)
		{
			$node->markInbound($this->nodes);
		}
	}

	public function updateHashes()
	{
		foreach($this->nodes as $node)
		{
			$node->updateHash();
			$this->replacements[$node->subject] = '_:' . $node->hashValue;
		}
	}

	public function replace()
	{
		$nodeList = array();
		foreach($this->replacements as $was => $now)
		{
			foreach($this->nodes as $node)
			{
				if(!strcmp($node->subject, $was))
				{
					emit("Replacing " . $node->subject . " with " . $now);
					$node->was = stringify(new RDFURI($node->subject));
					$node->subject = new RDFURI($now);					
				}
				foreach($node->triples as $k => $triple)
				{
					if(!strcmp($triple->subject, $was))
					{
						$triple->subject->value = $now;
					}
					if($triple->object instanceof RDFURI && !strcmp($triple->object, $was))
					{
						$triple->object->value = $now;
					}
					$node->triples[$k] = $triple;
				}
			}
		}
	}

	public function sort()
	{
		$c = 0;
		foreach($this->nodes as $node)
		{
			$k = sprintf('%s %04d', $node->subject, $c);
			$nodeList[$k] = $node;
			$c++;
		}
		ksort($nodeList);
		$this->nodes = $nodeList;
		foreach($this->nodes as $node)
		{
			$node->sortTriples($node->triples);
		}
	}
}

class BNode
{
	public $subject;
	public $triples;
	public $hashValue;
	public $nonDeterministicHash;
	public $bNode;
	public $objectHashes = array();
	public $inbound = array();

	public function __construct($subject, $triples)
	{
		$this->subject = strval($subject);
		$this->hashValue = null;
		$hval = array();
		if(!strncmp($this->subject, '_:', 2) || !strncmp($this->subject, '#', 1))
		{
			$subj = '#';
			$this->bNode = true;
		}
		else
		{
			$subj = '<' . strval($this->subject) . '>';
		}
		$this->sortTriples($triples, true);
		foreach($this->triples as $trip)
		{
			if($trip->object instanceof RDFURI && (!strncmp($trip->object, '_:', 2) || !strncmp($trip->object, '#', 1)))
			{
				$hval[] = $subj . ' <' . $trip->predicate . '> #';
			}
			else
			{
				$hval[] = $subj . ' <' . $trip->predicate . '> ' . stringify($trip->object);
			}
		}
		sort($hval);
		$this->nonDeterministicHash = sha1(implode("\n", $hval));
	}

	public function sortTriples($triples, $dedupe = false)
	{
		$this->triples = array();
		$c = 0;
		foreach($triples as $triple)
		{
			$k = '<' . $triple->predicate . '> ' . stringify($triple->object);
			if(!$dedupe)
			{
				$k .= sprintf('%04d', $c);
			}
			$c++;			
			$this->triples[$k] = $triple;
		}
	}
		
	public function updateHash()
	{
		sort($this->inbound);
		$hashSource = $this->nonDeterministicHash . ' ' . implode("\n", $this->inbound);
		emit('hashSource (' . $this->subject. '): ' . $hashSource);
		$this->hashValue = sha1($hashSource);
	}

	protected function shouldSkip($predicate)
	{
		if($predicate instanceof RDFTriple)
		{
			$predicate = $predicate->predicate;
		}
		if(!strcmp($predicate, RDF::rdf.'ID') ||
		   !strcmp($predicate, RDF::rdf.'about') ||
		   !strcmp($predicate, RDF::rdf.'nodeID'))
		{
			return true;
		}
	}
   
	public function markInbound(&$nodes)
	{
		foreach($this->triples as $triple)
		{
			if($this->shouldSkip($triple->predicate))
			{
				continue;
			}
			if(!($triple->object instanceof RDFURI))
			{
				continue;
			}
			$obj = strval($triple->object);
			if(!isset($nodes[$obj]))
			{
				emit('Failed to locate ' . $obj);
				continue;
			}
			$inboundValue = '<' . $triple->predicate . '> ' . $this->nonDeterministicHash;
			emit('Adding ' . $inboundValue . ' to ' . $obj . ' from ' . $this->subject);
			$nodes[$obj]->inbound[] = $inboundValue;
		}
	}

	public function __toString()
	{
		return strval($this->subject);
	}
}

require_once('form.phtml');
