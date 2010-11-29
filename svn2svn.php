<?php

include('xml.php');
define('DS', DIRECTORY_SEPARATOR);

function execSvn($params, $xml = true) {
	$execParams = array();
	if ($xml) {
		$execParams[] = '--xml';
	}
	$execParams = array_merge($execParams, $params);
	$exec = 'svn ' . implode(' ', array_map('escapeshellarg', $execParams));
	echo date('[d/m/y H:i] ') . "* Executing: $exec\n";
	$return = shell_exec($exec);
	if ($xml) {
		return Xml::toArray(new SimpleXMLElement($return));
	}
	return $return;
}

function upRev($path, $rev) {
	if (!isset($path['@'])) {
		$updates = array();
		$p = array();
		foreach ($path as $key => $subpath) {
			$p[$key] = $subpath['@'];
		}
		array_multisort($p, SORT_ASC, $path);
		foreach ($path as $subpath) {
			$updates[] = upRev($subpath, $rev);
		}
		return $updates;
	}
	global $dirOrigem, $dirDestino, $infoOrigem;
	if (strpos($path['@'], $infoOrigem['subPath']) !== 0) {
		return false;
	}
	$newPath = substr($path['@'], $infoOrigem['sizeSubPath']);
	execSvn(array('up', '-r', $rev, $dirOrigem . $newPath), false);
	clearstatcache();
	switch ($path['@action']) {
		case 'M':
		case 'R':
			if (is_dir($dirOrigem . $newPath)) { // Changing properties
				echo date('[d/m/y H:i] ') . "Modificacao de diretorio, ignorando\n";
				return false;
			}
			echo date('[d/m/y H:i] ') . "Arquivo modificado e copiado\n";
			if (!copy($dirOrigem . $newPath, $dirDestino . $newPath)) {
				exit(date('[d/m/y H:i] ') . "Falha ao copiar arquivo.");
			}
			return $dirDestino . $newPath;
		case 'A':
			$destino = $dirDestino . $newPath;
			if (isset($path['@copyfrom-path'])) {
				global $argv;
				$info = execSvn(array('info', $argv[2]));
				$result = execSvn(array('copy', '-r', $info['info']['entry']['@revision'], $dirDestino . substr($path['@copyfrom-path'], $infoOrigem['sizeSubPath']), $destino), false);
				if (empty($result)) {
					copy($dirOrigem . $newPath, $destino);
					execSvn(array('add', $destino), false);
					echo date('[d/m/y H:i] ') . "Nao foi encontrado o arquivo na ultima versao do repositorio, copiando sem historico.\n";
				}
				return $destino;
			}
			if (is_dir($dirOrigem . $newPath)) {
				echo date('[d/m/y H:i] ') . "Apenas criacao de diretorio\n";
				mkdir($destino, 0755, true);
				execSvn(array('add', $destino), false);
				return $destino;
			}
			if (!is_dir(dirname($destino))) {
				echo date('[d/m/y H:i] ') . "Criando diretorio para o novo arquivo\n";
				mkdir(dirname($destino), 0755, true);
			}
			echo date('[d/m/y H:i] ') . "Criando arquivo.\n";
			if (!copy($dirOrigem . $newPath, $destino)) {
				exit(date('[d/m/y H:i] ') . "Falha ao copiar arquivo.");
			}
			execSvn(array('add', $destino), false);
			return $destino;
		case 'D':
			echo date('[d/m/y H:i] ') . "Excluindo arquivo\n";
			execSvn(array('up', $dirDestino . $newPath), false);
			execSvn(array('rm', '--force', $dirDestino . $newPath), false);
			return $dirDestino . $newPath;
	}
	echo date('[d/m/y H:i] ') . "*** ATENCAO *** ACTION NAO SUPORTADA: " . $path['@action'] . "\n";
	return false;
}

function changesFilter($data, $tree = array()) {
	$result = DS;
	foreach ($data as $path) {
		$dirs = array_filter(explode(DS, $path));
		$pointer =& $tree;
		foreach ($dirs as $dir) {
			if (!isset($pointer[$dir])) {
				$pointer[$dir] = array();
			}
			$pointer =& $pointer[$dir];
		}
	}
	foreach ($tree as $path => $items) {
		$length = count($items);
		if ($length === 1) {
			$result .= $path . changesFilter(array(), $items);
		} elseif ($length > 1) {
			$result .= $path;
		} else {
			break;
		}
	}

	return $result;
}

if (function_exists('date_default_timezone_set')) {
	date_default_timezone_set('America/Sao_Paulo');
}

if (!isset($argv[1], $argv[2])) {
	exit(date('[d/m/y H:i] ') . "Informe os caminhos de origem e destino\n");
}

$dirOrigem = dirname(__FILE__) . DS . '_origem';
$dirDestino = dirname(__FILE__) . DS . '_destino';

// Cria os diretórios se não existir
if (!is_dir($dirOrigem)) {
	mkdir($dirOrigem, 0755, true);
}
if (!is_dir($dirDestino)) {
	mkdir($dirDestino, 0755, true);
}

$infoOrigem = execSvn(array('info', $argv[1]));
if (empty($infoOrigem['info'])) {
	exit(date('[d/m/y H:i] ') . "Origem não encontrada.\n");
}
$infoOrigem['sizeSubPath'] = strlen($infoOrigem['info']['entry']['url']) - strlen($infoOrigem['info']['entry']['repository']['root']);
$infoOrigem['subPath'] = substr($infoOrigem['info']['entry']['url'], -1 * $infoOrigem['sizeSubPath']);
$infoDestino = execSvn(array('info', $argv[2]));

if (!is_dir($dirOrigem . DS . '.svn')) {
	$firstRev = execSvn(array('log', '-r', '1:HEAD', '--limit', '1', '--stop-on-copy', $argv[1]));
	if (!isset($firstRev['log']['logentry']['@revision'])) {
		exit(date('[d/m/y H:i] ') . "Não achou commits na origem.\n");
	}
	chdir($dirOrigem);
	execSvn(array('co', '-r', $firstRev['log']['logentry']['@revision'], $argv[1], '.'), false);
}

if (!is_dir($dirDestino . DS . '.svn')) {
	if (empty($infoDestino['info'])) { // Não existe no repositório de destino
		if (!isset($firstRev)) {
			$firstRev = execSvn(array('log', '-r', '1:HEAD', '--limit', '1', '--stop-on-copy', $argv[1]));
			if (!isset($firstRev['log']['logentry']['@revision'])) {
				exit(date('[d/m/y H:i] ') . "Não achou commits na origem.\n");
			}
			chdir($dirOrigem);
			execSvn(array('up', '-r', $firstRev['log']['logentry']['@revision']), false);
		}
		execSvn(array('import', $dirOrigem, $argv[2], '-m', $firstRev['log']['logentry']['msg'] . "\nAutor: " . $firstRev['log']['logentry']['author'] . "\nData: " . date("d/m/Y H:i:s", strtotime($firstRev['log']['logentry']['date']))), false);
	}
	chdir($dirDestino);
	execSvn(array('co', $argv[2], '.'), false);
}

$infoAtual = execSvn(array('info', $dirOrigem));
$logs = execSvn(array('log', '-r', $infoAtual['info']['entry']['@revision'] . ':HEAD', '-q', $argv[1]));
$revs = array();
foreach ($logs['log']['logentry'] as $log) {
	$revs[] = $log['@revision'];
}
unset($logs);
echo date('[d/m/y H:i] ') . "Encontrado " . count($revs) . " para enviar...\n";

chdir($dirDestino);
array_shift($revs); // Remove a primeira, pois é onde está
foreach ($revs as $rev) {
	echo date('[d/m/y H:i] ') . "\nAplicando revisao $rev\n==========================\n";
	$alteracoes = execSvn(array('log', '-r', $rev, '-v', $argv[1]));
	if (empty($alteracoes['log']['logentry']['paths']['path'])) {
		// Não deveria entrar aqui, mas serve de proteção.
		continue;
	}
	$msg = $alteracoes['log']['logentry']['msg'] . "\nAutor: " . $alteracoes['log']['logentry']['author'] . "\nData: " . date('d/m/Y H:i:s', strtotime($alteracoes['log']['logentry']['date']));
	$msg = str_replace(array("\r\n", "\n"), PHP_EOL, $msg);
	$alteracoesDestino = upRev($alteracoes['log']['logentry']['paths']['path'], $rev);

	$alteracoesDestino = array_filter((array)$alteracoesDestino);
	if (empty($alteracoesDestino)) {
		execSvn(array('up', '-r', $rev, '-N', $dirOrigem), false);
		echo date('[d/m/y H:i] ') . "Sem alteracoes (provavel mudanca de properties)\n==========================\n";
		continue;
	}

	if (count($alteracoesDestino) > 100) {
		$alteracoesDestino = array(changesFilter($alteracoesDestino));
	}
	$exec = array_merge(array('commit', '--force-log', '-m', $msg), $alteracoesDestino);
	$result = execSvn($exec, false);
	if (strpos($result, 'Commit da rev') === false && strpos($result, 'Committed revision') === false) {
		exit(date('[d/m/y H:i] ') . "Falha ao commitar: $result\n");
	} else {
		execSvn(array('up', '-r', $rev, '-N', $dirOrigem), false);
	}
	echo date('[d/m/y H:i] ') . "Fim da migracao desta rev\n==========================\n";
}
