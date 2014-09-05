<?php
/*
   libiptables-php github.com/koosha--/libiptablesphp
   This light-weight library provides you with a number of methods in 
   PHP to read and change different configurations of iptables rules.
   Copyright (C) 2011-2013 Koosha K. M. (koosha.khajeh@gmail.com)

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 
 */
/**
 * @package libiptables-php
 * This is a lightweight library for manipulating iptables rules.
 * @author Koosha K. M. (koosha.khajeh@gmail.com)
 * @version 1.0.0
 * @copyright (c) 2011 - 2013 Koosha K. M.
 * @license GPL
 */

/**
 * Modified by Jeremy Lisenby on 8/14/2014
 * Added flushChain method
 * Added IPv6 handling
 * Customized serveral interfaces to allow passing of extra parameters
 * Fixed bug in getReferringRules method, it was resolving rule index incorrectly
 * Fixed bug in constructor, iptables-save is an executable, but "iptables-save -c" is not an executable
 * Changed calls to iptables-* to optionally use sudo
 * Added debug flags plus statements
 */

/**
 * Modified by Jeremy Lisenby on 9/4/2014
 * Fixed problems with index management for rules arrays
 */

class IptablesConfig
{
	/**
	 * The path of rules file to be parsed by the library
	 * @access private 
	 */
	private $rulesFile;
	/**
	 * The contents of the rules file in string
	 * @access private
	 */
	private $fileString;
	/**
	 * An array holding the names of the tables defined in the rules file
	 * @access private 
	 */
	private $tables;
	/** 
	 * An associative tree-structured array to store different values to represent different parts of the file
	 * @access private 
	 */
	private $fileTree;
	/**
	 * The tables of iptables
	 * @access private
	 */
	private $validTables;
	/** 
	 * The tables of iptables and their respective built-in chains
	 * @access private
	 */
	private $builtin;
	/**
	 * Instantiates an object of the class that points to the given file path.
	 * 
	 * The constructor of the class. If you supply a file path as the argument of the constructor,
	 * the library tries to read that file. However, if you do not supply a file path, the library
	 * attempts to read the current rules followed by iptables that are located in memory. i.e. 
	 * it executes the iptables-save command to retrieve a textual representation of the rules.
	 * @param string $rulesFile The path of the rules file
	 * @return void
	 */
	private $ipt_save;
	private $ipt_restore;
	private $sudo_cmd;
	public $debug;
	function __construct($rulesFile = NULL, $ipversion = 4, $sudo_cmd = '')
	{
		$this->debug = false;
		$this->validTables = array('filter', 'nat', 'mangle', 'raw');
		$this->builtin = array(
		'filter' => array('INPUT', 'FORWARD', 'OUTPUT'),
		'nat'    => array('PREROUTING', 'OUTPUT', 'POSTROUTING'),
		'mangle' => array('PREROUTING', 'OUTPUT', 'INPUT', 'FORWARD', 'POSTROUTING'),
		'raw'    => array('PREROUTING', 'OUTPUT')
		);

		$this->sudo_cmd = '';
		if ($sudo_cmd)
			$this->sudo_cmd = $sudo_cmd.' ';

		$this->ipt_save = '/sbin/iptables-save';
		if ($ipversion == 6)
			$this->ipt_save = '/sbin/ip6tables-save';

		$this->ipt_restore = '/sbin/iptables-restore';
		if ($ipversion == 6)
			$this->ipt_restore = '/sbin/ip6tables-restore';

		$fileString = '';
		if ($rulesFile == NULL) {
			$this->rulesFile = NULL;
			if (is_executable($this->ipt_save)) {
				exec($this->sudo_cmd.$this->ipt_save.' -c', $output, $return_val);
				if ($return_val == 0) {
					foreach ($output as $line)
						$fileString .= $line."\n";
				}
				else {
					die ("Error: Unable to run $this->ipt_save properly. Is the module loaded in the kernel?");
				}
			}
			else
				die ('Error: Cannot find or execute iptables-save ('.$this->ipt_save.')');
		}
		else {
			$this->rulesFile = $rulesFile;
			if (file_exists($rulesFile)) {
				$fp = fopen($rulesFile, 'r');
				while ($line = fgets($fp, 10240))
					$fileString .= $line;
				fclose($fp);
			}
			else {
				/* Just create the file */
				if (!($fp = fopen($rulesFile, 'w+')))
					die ('Error: Unable to create the file '.$rulesFile.'.');
				fclose($fp);
			}
		}
		$this->fileString = $fileString;
		$this->tables = array();
		$this->fileTree = array();
		$this->parseFile();
	}
	/**
	 * Returns an array of strings containing the list of all tables defined in the file.
	 * @access public
	 * @return array
	 */
	public function getAllTables()
	{
		$tables = array();
		foreach ($this->fileTree as $table => $children)
			$tables[] = $table;
		return $tables;
	}
	/**
	 * Returns an array of strings containing the names of the chains associated with a table.
	 * @access public
	 * @param string $table The name of the table
	 * @return An array of strings containing the names of the chains; NULL if the supplied table does not exist.
	 */
	public function getTableChains($table)
	{
		$chains = array();
		if  (!in_array($table, $this->tables))
			return NULL;
		if (isset($this->fileTree[$table]))
			foreach ($this->fileTree[$table] as $chain => $children)
				$chains[] = $chain;
		return $chains;
	}
	/**
	 * Checks whether a chain is a built-in one or not
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return boolean true if chain is built-in; false if it is not built-in.
	 */
	public function isBuiltinChain($table, $chain)
	{
		return (in_array($table, array_keys($this->builtin)) && in_array($chain, $this->builtin[$table]));
	}
	/**
	 * Adds a user-defined chain to a table.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chainName The name of new chain
	 * @param integer $packetcount The value of packet counter. Default is set to zero.
	 * @param integer $bytecount The value of byte counter. Default is set to zero.
	 * @return boolean true on success; false otherwise. 
	 */
	public function addChain($table, $chainName, $packetcount = '0', $bytecount = '0')
	{
		if (!in_array($table, $this->validTables) || in_array($chainName, $this->getTableChains($table)) || !is_numeric($bytecount) || !is_numeric($bytecount)){
			if ($this->debug) {
				echo "addChain ($chainName) failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		if (!in_array($table, $this->getAllTables()) && in_array($table, $this->validTables))
			$this->fileTree[$table] = array();
		$this->fileTree[$table][$chainName] = array();
		$this->fileTree[$table][$chainName]['packet-counter'] = $packetcount;
		$this->fileTree[$table][$chainName]['byte-counter'] = $bytecount;
		return true;
	}
	/**
	 * Renames a user-defined chain in a table. Note that you can only change the name of user-defined chains; builtin chains cannot have different names.
	 * @access public
	 * @param string $table The name of table
	 * @param string $oldName The current name of chain
	 * @param string $newName The name that you intend to change to
	 * @param boolean $cascade If is set to true, the rules of the table that refer to this chain will be affected. The default value is true.
	 * @return boolean true if changed the name successfully; false otherwise.
	 */
	public function renameChain($table, $oldName, $newName, $cascade = true)
	{
		$chains = $this->getTableChains($table);
		if (($this->isBuiltinChain($table, $oldName)) || 
			!is_array($chains) || 
			count($chains) == 0 || 
			(count($chains) > 0 && !in_array($oldName, $chains)) ||
			(count($chains) > 0 && in_array($newName, $chains) && $oldName != $newName)){
			if ($this->debug) {
				echo "renameChain ($oldName) to ($newName) failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		else  {
			if ($cascade)
				if (($n = $this->getReferenceNum($table, $oldName)) != NULL && $n > 0) {
					$rules = $this->getReferringRules($table, $oldName);
					foreach ($rules as $rule) // $rule == ['chain' => chainName, 'index' => indexInChain]
						if (isset($this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['g']))
							$this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['g'] = $newName;
						elseif (isset($this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['goto']))
							$this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['goto'] = $newName;
						elseif (isset($this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['j']))
							$this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['j'] = $newName;
						elseif (isset($this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['jump']))
							$this->fileTree[$table][$rule['chain']]['rules'][$rule['index']]['jump'] = $newName;
				}	
			if (isset($this->fileTree[$table][$oldName]['rules'])) {
				foreach ($this->fileTree[$table][$oldName]['rules'] as $rule) {
					if (isset($rule['A']) && trim($rule['A']) == $oldName)
						$rule['A'] = $newName;
				}
			}
			$this->fileTree[$table][$newName] = $this->fileTree[$table][$oldName];
			unset($this->fileTree[$table][$oldName]);
			return true;
		}
	}
	/**
	 * Removes a user-defined chain from a table. If the chain is referenced by some rules, it cannot be removed. You must first delete the referring rules.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain to be removed
	 * @return boolean true on success; false otherwise. 
	 */
	public function removeChain($table, $chain)
	{
		if (!in_array($table, $this->getAllTables()) || !in_array($chain, $this->getTableChains($table)) || $this->isBuiltinChain($table, $chain) || count($this->getReferenceNum($table, $chain)) > 0){
			if ($this->debug) {
				echo "removeChain ($chain) failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		unset($this->fileTree[$table][$chain]);
		return true;
	}
	/**
	 * Deletes all rules in $chain
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return boolean true on success; false otherwise
	 */
	public function flushChain($table, $chain)
	{
		if (isset($this->fileTree[$table][$chain]['rules']) && count($this->fileTree[$table][$chain]['rules'] > 0)) {
			$this->fileTree[$table][$chain]['rules'] = array();
			return true;
		}

		if ($this->debug) {
			echo "flushChain ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Returns the policy of a built-in chain.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return string The policy of chain if the chain is built-in and exists; NULL otherwise.
	 */
	public function getPolicy($table, $chain)
	{
		if ($this->isBuiltinChain($table, $chain))
			return $this->fileTree[$table][$chain]['policy'];
		return NULL;
	}
	/**
	 * Sets the policy of a built-in chain.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param string $policy The policy that chain should be set to. Possible values are: ACCEPT, DROP, QUEUE, and RETURN.
	 * @return boolean true on success; false otherwise
	 */
	public function setPolicy($table, $chain, $policy)
	{
		if ($this->isBuiltinChain($table, $chain) &&
			($policy == 'ACCEPT' ||
			 $policy == 'DROP'   ||
			 $policy == 'QUEUE'  ||
			 $policy == 'RETURN')) {
			$this->fileTree[$table][$chain]['policy'] = $policy;
			return true;
		}

		if ($this->debug) {
			echo "setPolicy failed ($chain) ($policy)\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Returns the byte counter of a chain.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return integer The value of byte counter. NULL if either the supplied table or chain does not exist.
	 */
	
	public function getChainByteCounter($table, $chain)
	{
		if (isset($this->fileTree[$table][$chain]['byte-counter']))
			return $this->fileTree[$table][$chain]['byte-counter'];
		return NULL;
	}
	/**
	 * Sets the byte counter of a chain to a given value.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $count A non-negative integer for the byte counter
	 * @return boolean true on success; false otherwise.
	 */
	public function setChainByteCounter($table, $chain, $count)
	{
		if (isset($this->fileTree[$table][$chain]) && is_numeric($count)) {
			$this->fileTree[$table][$chain]['byte-counter'] = $count;
			return true;
		}
		if ($this->debug) {
			echo "setChainByteCounter failed ($chain)\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Returns the packet counter of a chain.
	 * @access public
	 * @param string $table The name of the table
	 * @param string $chain The name of the chain
	 * @return integer The packet counter of the chain
	 */
	public function getChainPacketCounter($table, $chain)
	{
		return $this->fileTree[$table][$chain]['packet-counter'];
	}
	/**
	 * Sets the packet counter of a chain to a given value.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $count A non-negative integer for the packet counter
	 * @return boolean true on success; false otherwise.
	 */
	public function setChainPacketCounter($table, $chain, $count)
	{
		if (isset($this->fileTree[$table][$chain]) && is_numeric($count)) {
			$this->fileTree[$table][$chain]['packet-counter'] = $count;
			return true;
		}
		if ($this->debug) {
			echo "setChainPacketCounter failed ($chain)\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Sets both the packet counter and the byte counter of a chain to zero.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return boolean True if both values are set to zero successfully; false otherwise.
	 */
	public function zeroChainCounters($table, $chain)
	{
		return ($this->setChainByteCounter($table, $chain, '0') && $this->setChainPacketCounter($table, $chain, '0'));
	}
	/**
	 * Returns how many references are made to a user-defined chain
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return integer The number of rules referring to a user-defined chain; NULL if either table/chain do not exist or chain is a built-in one 
	 */
	public function getReferenceNum($table, $chain)
	{
		$num = $this->getReferringRules($table, $chain);
		if ($num != NULL)
			return count($num);
		return NULL;			
	}
	/**
	 * Returns an array containing elements each of which is an associative array itself that 
	 * indicates the chain that the given user-defined chain is referenced by (key 'chain') and 
	 * the index of referring rule (key 'index')
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return array An array of associative arrays; NULL if table does not exist or if chain is a built-in one
	 */  
	public function getReferringRules($table, $chain)
	{
		if (!isset($this->fileTree[$table]) || $this->isBuiltinChain($table, $chain))
			return NULL;
		$return = array();
		foreach ($this->fileTree[$table] as $mychain => $children)
			if (isset($children['rules'])){
				$i = 0;
				foreach ($children['rules'] as $rule)
				{
					foreach ($rule as $name => $value)
						if (($name == 'g' || $name == 'goto' || $name == 'j' || $name == 'jump') && trim($value) == $chain)
							$return[] = array('chain' => $mychain, 'index' => $i);
					$i++;
				}
			}
		/* reverse the rules that the caller may iterate the list with foreach and delete from the chain. */
		return  array_reverse($return);
		
	}
	/**
	 * Returns an array of associate arrays. Each associate array represents a rule.
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return array An array of associate arrays if the supplied table and chain exist; NULL otherwise.
	 */
	public function getAllRules($table, $chain)
	{
		if (isset($this->fileTree[$table][$chain]['rules']))
			return $this->fileTree[$table][$chain]['rules'];
		return NULL;
	}
	/**
	 * Returns a rule of a chain. The rule is represented as an associative array.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $index A zero-based index indicating the position of rule
	 * @return array An associative array representing the rule if the supplied table, chain, and index exist; NULL otherwise.
	 */
	public function getRule($table, $chain, $index)
	{
		if (isset($this->fileTree[$table][$chain]['rules'][$index]))
			return $this->fileTree[$table][$chain]['rules'][$index];
		return NULL;
	}
	/**
	 * Returns an array of strings. Each string is the textual representation of a rule as defined in the file.
	 * Note that this string is not updated as you add/remove/change rules. It's only useful for printing.
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @return array An array of rules in string; NULL if the supplied table and chain either do not exist or do not have any rules.
	 */
	public function getAllRuleStrings($table, $chain)
	{
		if (isset($this->fileTree[$table][$chain]['stringrules']))
			return $this->fileTree[$table][$chain]['stringrules'];
		return NULL;
	}
	/**
	 * Adds a rule at the position of $index and shifts the rules thereafter to higher positions 
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $index A zero-based index indicating the position of new rule
	 * @param array $ruleArray An associative array representing the rule
	 * @return boolean true on success; false otherwise
	 */ 
	public function insertRule($table, $chain, $index, array $ruleArray)
	{
		if (!isset($this->fileTree[$table][$chain])){
			if ($this->debug) {
				echo "insertRule to ($chain) failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		
		if (!isset($this->fileTree[$table][$chain]['rules']))
			$this->fileTree[$table][$chain]['rules'] = array();

		$c = count($this->fileTree[$table][$chain]['rules']);

		/* If the rule should be in the middle of rules array, we have to shift the remaining rules after the $index */
		if (0 <= $index && $index <= $c) {
			array_splice($this->fileTree[$table][$chain]['rules'], $index, 0, array($ruleArray));
		}
		elseif ($index >= $c)
			$this->fileTree[$table][$chain]['rules'][] = $ruleArray;
			
		return true;
	}
	/**
	 * Appends a rule at the end of a chain
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param array $ruleArray An associative array representing the rule
	 * @return boolean true if the supplied table and chain exist; false otherwise
	 */
	public function appendRule($table, $chain, array $ruleArray)
	{
		if (isset($this->fileTree[$table][$chain])) {
			if (!isset($this->fileTree[$table][$chain]['rules']))
				$this->fileTree[$table][$chain]['rules'] = array();
			$this->fileTree[$table][$chain]['rules'][] = $ruleArray;
			return true;
		}

		if ($this->debug) {
			echo "appendRule to ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Deletes the rule at index $index 
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $index A zero-based index indicating the position of rule
	 * @return boolean true on success; false otherwise
	 */
	public function removeRule($table, $chain, $index)
	{
		if (isset($this->fileTree[$table][$chain]['rules']) && 0 <= $index && $index < count($this->fileTree[$table][$chain]['rules'])) {
			array_splice($this->fileTree[$table][$chain]['rules'], $index, 1);
			return true;
		}

		if ($this->debug) {
			echo "removeRule from ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Replaces the rule at index $index with $ruleArray rule
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param string $index A zero-based index indicating the position of rule
	 * @param array $ruleArray An associative array representing the rule
	 * @return boolean true on success; false otherwise
	 */
	public function replaceRule($table, $chain, $index, array $ruleArray)
	{
		if (isset($this->fileTree[$table][$chain])) {
			if (isset($this->fileTree[$table][$chain]['rules'])) {
				if(0 <= $index && $index < count($this->fileTree[$table][$chain]['rules'])) {
					$this->fileTree[$table][$chain]['rules'][$index] = $ruleArray;
					return true;
				}
				elseif ($index >= count($this->fileTree[$table][$chain]['rules']))
					return $this->appendRule($table, $chain, $ruleArray);
			}
			else {
				$this->fileTree[$table][$chain]['rules'] = array();
				$this->fileTree[$table][$chain]['rules'][] = $ruleArray;
				return true;
			}
		}

		if ($this->debug) {
			echo "replaceRule in ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Returns the textual representation of a rule as defined in the file.
	 * Note that this string is not updated as you add/remove/change rules. It's only useful for printing.
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $index A zero-based index indicating the position of rule
	 * @return string The textual representation of a rule in the file; NULL if either table and chain do not exist or do not have such an index 
	 */
	public function getRuleString($table, $chain, $index)
	{
		if (isset($this->fileTree[$table][$chain]['stringrules'][$index]))
			return $this->fileTree[$table][$chain]['stringrules'][$index];
		return NULL;
	}
	/**
	 * Changes the position of a rule in the rules set of a chain, and then shifts the remaining rules to the left or to the right accordingly
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $oldIndex A zero-based index indicating the current position of rule
	 * @param integer $newIndex A zero-based index indicating the new position of rule
	 * @return boolean true on success; false otherwise
	 */
	public function changeRuleIndex($table, $chain, $oldIndex, $newIndex)
	{
		if (!isset($this->fileTree[$table][$chain]['rules'][$oldIndex]) || $oldIndex == $newIndex) {
			if ($this->debug) {
				echo "changeRuleIndex in ($chain) failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		$tmp = $this->fileTree[$table][$chain]['rules'][$oldIndex];
		$this->removeRule($table, $chain, $oldIndex);
		$this->insertRule($table, $chain, $newIndex, $tmp);
		return true;
	}
	/**
	 * Returns the byte counter of a rule if available.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $ruleIndex A zero-based index indicating the position of the intended rule
	 * @return integer The byte counter of a rule if defined; NULL otherwise. 
	 */
	public function getRuleByteCounter($table, $chain, $ruleIndex)	
	{
		if (isset($this->fileTree[$table][$chain]['rules'][$ruleIndex]['byte-counter']))
			return $this->fileTree[$table][$chain]['rules'][$ruleIndex]['byte-counter'];
		return NULL;
	}
	/**
	 * Sets the byte counter of a rule to a given value.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param string $ruleIndex A zero-based index indicating the position of the intended rule
	 * @param integer $byteCounter The integer value that the byte counter should be set to
	 * @return boolean true on success; false otherwise
	 */
	public function setRuleByteCounter($table, $chain, $ruleIndex, $byteCounter)
	{
		if (isset($this->fileTree[$table][$chain]['rules'][$ruleIndex])) {
			$this->fileTree[$table][$chain]['rules'][$ruleIndex]['byte-counter'] = $byteCounter;
			return true;
		}

		if ($this->debug) {
			echo "setRuleByteCounter in ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Returns the packet counter of a rule if available.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $ruleIndex A zero-based index indicating the position of the intended rule
	 * @return integer The packet counter of a rule if defined; NULL otherwise. 
	 */
	public function getRulePacketCounter($table, $chain, $ruleIndex)
	{
		if (isset($this->fileTree[$table][$chain]['rules'][$ruleIndex]['packet-counter']))
			return $this->fileTree[$table][$chain]['rules'][$ruleIndex]['packet-counter'];
		return NULL;
	}
	/**
	 * Sets the packet counter of a rule to a given value.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param string $ruleIndex A zero-based index indicating the position of the intended rule
	 * @param integer $packetCounter The integer value that the packet counter should be set to
	 * @return boolean true on success; false otherwise
	 */
	public function setRulePacketCounter($table, $chain, $ruleIndex, $packetCounter)
	{
		if (isset($this->fileTree[$table][$chain]['rules'][$ruleIndex])) {
			$this->fileTree[$table][$chain]['rules'][$ruleIndex]['packet-counter'] = $packetCounter;
			return true;
		}

		if ($this->debug) {
			echo "setRulePacketCounter in ($chain) failed\n";
			print_r(func_get_args());
		}
		return false;
	}
	/**
	 * Sets both the packet counter and the byte counter of a rule to zero.
	 * @access public
	 * @param string $table The name of table
	 * @param string $chain The name of chain
	 * @param integer $ruleIndex A zero-based index indicating the position of the intended rule
	 * @return boolean true if both values are set to zero successfully; false otherwise.
	 */
	public function zeroRuleCounters($table, $chain, $ruleIndex)
	{
		return ($this->setRuleByteCounter($table, $chain, $ruleIndex, 0) && $this->setRulePacketCounter($table, $chain, $ruleIndex, 0));
	}
	/**
	 * Transforms the current state of different parameters to a format that is readable by iptables-restore
	 * @access public
	 * @param string $file The file that will contain generated rules. By default, the output is written to the file specified at the time of instanciation. 
	 * However, if no file path was supplied to the constructor, you must specify a file path here; otherwise, the method will fail.
	 * @return boolean true on success; false otherwise.
	 */ 
	public function commit($file = NULL)
	{
		if ($file == NULL && $this->rulesFile == NULL) {
			if ($this->debug) {
				echo "commit failed\n";
				print_r(func_get_args());
			}
			return false;
		}
		$time = date("D M j G:i:s T Y");
		$content = "# Generated by libiptables-php on $time - github.com/koosha--/libiptablesphp\n";
		foreach ($this->fileTree as $table => $chains) {
			$content .= "*$table\n";
			foreach ($chains as $mychain => $properties) {
				$policy = isset($properties['policy']) ? $properties['policy'] : '-';
				$content .= ":$mychain $policy [{$properties['packet-counter']}:{$properties['byte-counter']}]\n";
			}
			foreach ($chains as $mychain => $properties) {
				if (isset($properties['rules'])) {
					foreach ($properties['rules'] as $myrule) {
						if (isset($myrule['packet-counter']) && isset($myrule['byte-counter'])) {
							$content .= "[{$myrule['packet-counter']}:{$myrule['byte-counter']}] ";
						}
						$content .= "-A $mychain ";
						if (isset($myrule['m'])) {
							$modules = preg_split('/\s*,\s*/', $myrule['m']);
							foreach ($modules as $m)
								$content .= "-m $m ";	
						}
						foreach ($myrule as $name => $value) {
							$invert = false;
							if ($name != 'A' && $name != 'packet-counter' && $name != 'byte-counter' && $name != 'm') {
								if ($name[0] == '!') {
									$content .= '! ';
									$name = substr($name, 1);
								}
								$content .= '-';
								if (strlen($name) > 1)
									$content .= '-';
								$content .= "$name $value ";
							}	
						}
						$content .= "\n";
					}
				}
			}
			$content .= "COMMIT\n";
		}
		if ($file != NULL)
			$fp = fopen($file, 'w');
		else
			$fp = fopen($this->rulesFile, 'w');
		
		if (!fwrite($fp, $content))
			return false;
		fclose($fp);
		return true;
	}
	/**
	 * Applies the current state of rules to iptables in order to be used immediately
	 * @param boolean $restoreCounters If set to true, current packet and byte counters will also be restored. If set to false, they will be ignored.
	 * @param string $iptRestore The path of iptables-restore command. The default path is set to use restore method established in constructor.
	 * @return boolean true on success; false otherwise.
	 */
	public function applyNow($restoreCounters = true, $iptRestore = NULL, $fileName = NULL)
	{
		if ($iptRestore === NULL)
			$iptRestore = $this->ipt_restore;

		if (is_executable($iptRestore)) {
			$tmp_path = $fileName;
			if ($fileName === NULL) {
				$date = rtrim(`/bin/date +"%s"`);
				$tmp_path = "/tmp/rules_$date";
			}
			$this->commit($tmp_path);
			if ($restoreCounters)
				$iptRestore .= ' -c';
			exec($this->sudo_cmd.$iptRestore.' '.$tmp_path, $output, $return_val);
			if ($return_val == 0)
				return true;
			else {
				$str = '';
				foreach ($output as $line)
					$str .= $line;
				die ($str);
			}
		}
		return false;
	}
	/**
	 * Extracts packet and byte counters and puts the extracted values in an associative array
	 * @access private
	 * @param string $string The input string containing counters
	 * @return array An associative array with keys 'packet-counter' and 'byte-counter'; NULL if the supplied string does not include counters
	 */
	private function parseCounters($string)
	{
		$string = trim($string);
		if (!preg_match('/^\[(?P<pc>\d+):(?P<bc>\d+)]$/', $string, $matches)) {
			return NULL;
		}
		$return = array();
		$return['packet-counter'] = $matches['pc'];
		$return['byte-counter'] = $matches['bc'];
        return $return;
	}


	/**
	 * Parses and extracts out different parameters from the rules file
	 * @access private
	 * @return void
	 */
	private function parseFile()
	{
		$inTable = false;
		$currentTable = NULL;
		$line = 0;
		$lines = explode("\n", $this->fileString);
		foreach($lines as $buffer) {
			$line++;
			$buffer .= "\n";
			if ($buffer[0] == '#' || $buffer[0] == "\n")
				continue;		
			elseif ($buffer == "COMMIT\n" && $inTable)
				$inTable = false;
			elseif ($buffer[0] == '*' && $inTable)
				die ("Error: COMMIT expected at line ".($line - 1).".");
			elseif ($buffer[0] == '*' && !$inTable && preg_match('/^\*\w+\s*\n/', $buffer, $matches)) {
				$tbl_name = trim($matches[0]);
				$currentTable = substr($tbl_name, 1);
				
				if (in_array($currentTable, $this->tables))
					die ("Error on line $line: table $currentTable already defined.");
				
				if (!$currentTable || strlen($currentTable) == 0)
					die ("Error on line $line: invalid table definition.");

				$this->tables[] = $currentTable;
				$inTable = true;
			}
			elseif ($buffer[0] == ':' && $inTable) {
				$chain = NULL;
				$policy = NULL;
				$tmp = preg_split('/\s+/', substr($buffer, 1));
				if (count($tmp) > 0) {
					$chain = $tmp[0];
					$ruleIndex = 0;
					if (count($tmp) > 1) {
						$policy = $tmp[1];
						$pcnt = $bcnt = 0;
						if (count($tmp) > 2 && is_array($counters = $this->parseCounters($tmp[2]))) {
							$pcnt = $counters['packet-counter'];
							$bcnt = $counters['byte-counter'];
						}
						else
							die ("Error on line $line: counters not specified.");
					}
					else
						die ("Error one line $line: policy is not specified.");
				}
				else
					die ("Error on line $line: invalid chain definition.");
				
				$this->fileTree[$currentTable][$chain] = array();
				$this->fileTree[$currentTable][$chain]['rules'] = array();
				$this->fileTree[$currentTable][$chain]['stringrules'] = array();
				if ($this->isBuiltinChain($currentTable, $chain)) /* Only built-in chains can have policy. */
					$this->fileTree[$currentTable][$chain]['policy'] = $policy;
				$this->fileTree[$currentTable][$chain]['packet-counter'] = $pcnt;
				$this->fileTree[$currentTable][$chain]['byte-counter'] = $bcnt;
				
			}		

			elseif ($inTable) {
				$startRule = 0;
				$newrule = array();
				if ($buffer[0] == '[') {
					preg_match('/\[\d+:\d+]/', $buffer, $matches);
					if (!$matches || count($matches) == 0)
						die ("Error on line $line: bad counters definition.");
					$countersArray = $this->parseCounters($matches[0]);
					if (is_array($countersArray) && isset($countersArray['packet-counter']) && isset($countersArray['byte-counter'])) {
						$newrule['packet-counter'] = $countersArray['packet-counter'];
						$newrule['byte-counter'] = $countersArray['byte-counter'];
					}
					else {
						$newrule['packet-counter'] = NULL;
						$newrule['byte-counter'] = NULL;
					}
					$startRule = strlen($matches[0]) + 1;
				}
				/*
				 * Handling rules
				 */
				$rule = substr($buffer, $startRule);
				$matches = preg_split('/(?:!\s+){0,1}\s+\-{1,2}/', $rule, NULL, PREG_GREP_INVERT);
				$modules = array();
				$invert = -1;
				for ($i = 0; $i < count($matches); $i++) {
					$matches[$i] = trim($matches[$i]);
					if (preg_match('/!$/', $matches[$i])) {
						$invert = $i + 1; /* Next option has !, that means it has to be inverted */
						$matches[$i] = trim(substr($matches[$i], 0, strlen($matches[$i]) - 1));
					}
					
					if ($invert == $i)
						$matches[$i] = trim('!' . $matches[$i]);
					
					$tmp = explode(' ', $matches[$i]);
					$name = $tmp[0];
					$value = '';
					
					if (count($tmp) > 1)
						$value = implode(' ', array_slice($tmp, 1));
							
					if ($name == '-A' && isset($tmp[1])) {
						$name = 'A';
						$ruleChain = $tmp[1];
					}
					elseif ($name == 'm') {
						$modules[] = $value;
						$m = implode(',', $modules);
					}
					if (count($modules) > 0)
						$newrule['m'] = $m;
					$newrule[$name] = $value;
				}
				$this->fileTree[$currentTable][$ruleChain]['rules'][] = $newrule;
				$this->fileTree[$currentTable][$ruleChain]['stringrules'][] = trim($buffer);
			}
		}
		if ($inTable)
			die ("Error: COMMIT expected at the end of file.");
	}
}
?>
