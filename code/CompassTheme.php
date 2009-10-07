<?php

class CompassTheme extends Object {
	
	static $gem_version_ok = null;
	
	static function gem_path() {
		$path = TEMP_FOLDER . '/gems';
		if (!file_exists($path)) mkdir($path, 0770);
		return $path;	
	}
	
	static function run($cmd, &$stdout, &$stderr) {
		$descriptorspec = array(
			0 => array("pipe", "r"), // stdin is a pipe that the child will read from
			1 => array("pipe", "w"), // stdout is a pipe that the child will write to
			2 => array("pipe", "w")  // stderr is a file to write to
		);
		 
		$gempath = self::gem_path();
		$process = proc_open("HOME='$gempath' GEM_HOME='$gempath' " . (@$_GET['flush'] ? "FLUSH={$_GET['flush']} " : '') . $cmd, $descriptorspec, $pipes);
		
		$stdout = "";
		$stderr = "";
		 
		if (!is_resource($process)) return -1;
		 
		fclose($pipes[0]); // close child's input immediately
		stream_set_blocking($pipes[1],false);
		stream_set_blocking($pipes[2],false);
		 
		while (true) {
			$read = array();
			if (!feof($pipes[1])) $read[]= $pipes[1];
			if (!feof($pipes[2])) $read[]= $pipes[2];
			 
			if (!$read) break;
			if (!stream_select($read, $w=null, $e=null, 120)) break;
			 
			foreach ($read as $r) {
				$s = fread($r,1024);
				if ($r == $pipes[1]) $stdout .= $s; else $stderr .= $s;
			}
		}
		 
		fclose($pipes[1]);
		fclose($pipes[2]);
		 
		return proc_close($process);
	}
	
	static function gemrequire($gem) {
		// Check that rubygems exists and is a good enough version
		if (self::$gem_version_ok === null) {
			$code = self::run('gem environment version', $ver, $err);
			if ($code !== 0) user_error('Problem accessing rubygems version - is ruby installed and rubygems available?', E_USER_ERROR);
			
			$ver = explode('.', $ver);
			if ($ver[0] < 1 || $ver[1] < 2) user_error('Rubygems must be at least version 1.2. Please upgrade', E_USER_ERROR);
			
			self::$gem_version_ok = true;
		}
		
		// See if the gem exists. If not, try adding it
		self::run("gem list -i $gem", $out, $err);
		if (trim($out) != 'true') {
			$code = self::run('gem sources --add http://gems.github.com/', $out, $err);
			if ($code !== 0) user_error("Could not add github as a gem source. Either manually add, or repair error. Error message was: $err", E_USER_ERROR);
			
			$code = self::run("gem install $gem", $out, $err);
			if ($code !== 0) user_error("Could not install required gem $gem. Either manually install, or repair error. Error message was: $err", E_USER_ERROR);
		}
	}
	
	static function gemrun($gem, $command, $ver=null, $args="", &$out, &$err) {
		if (!$ver) $ver = '>= 0';
		return self::run("ruby -rubygems -e 'gem \"$gem\", \"$ver\"' -e 'load \"$command\"' -- $args", $out, $err);
	}
	
	static function rebuild() {
		// If ruby isn't present, don't throw an error, just accept it
		$ver = `ruby --version`;
		if (!$ver) return;

		self::gemrequire('yard');
		self::gemrequire('maruku');
		self::gemrequire('haml');
		self::gemrequire('chriseppstein-compass');
		self::gemrequire('chriseppstein-compass-colors');
		
		global $_CSS_MANIFEST;
		$dirs = array();
		foreach ($_CSS_MANIFEST as $cssfile) {
			if (@$cssfile['unthemed']) $dirs[] = dirname(dirname($cssfile['unthemed']));
			if (@$cssfile['themes']) {
				foreach ($cssfile['themes'] as $theme => $file) $dirs[] = dirname(dirname($file)); 
			}
		}

		$gemdir = BASE_PATH.DIRECTORY_SEPARATOR.'compass'.DIRECTORY_SEPARATOR.'gems';
		$orig = getcwd();
		
		foreach (array_unique($dirs) as $dir) {
			// If this is in the compass module, skip
			if (preg_match('{^compass/}', $dir)) continue;
			
			// If there isn't a config.rb here, skip
			$dir = BASE_PATH.DIRECTORY_SEPARATOR.$dir;
			if (!file_exists($dir.DIRECTORY_SEPARATOR.'config.rb')) continue;
			
			chdir($dir);
			$code = self::gemrun("chriseppstein-compass", "compass", null, @$_GET['flush'] ? " --force" : "", $out, $err);
			
			if ($code !== 0) user_error('Compass error<br />'.preg_replace('/\n/', '<br />', $err), E_USER_ERROR);
		}
		
		chdir($orig);
	}
}