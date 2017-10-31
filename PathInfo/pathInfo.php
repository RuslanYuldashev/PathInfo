<?php

class _pathInfo {
	private function GetStartPoint($path) {
		$isStart = array();
		foreach ($path as $key => $val) 
		{
			echo $key.': '.$val->to.'<br>';
			$isStart[$key]++;
			$isStart[$val->to]--;
		}
		print_r($isStart);
		foreach ($path as $val) {
			if ($val == 1) {
				return $key;
			}
		}
		return -1;
	}
	
	private function fromDataToString($Step, $CurTown) {
		$s = 'Take '.$Step->transport.' from '.$CurTown.' to '.$Step->to.'. ';
		foreach ($Step as $key => $val)
			if ( strcmp($key, 'to') && strcmp($key, 'transport')) 
				$s .= $key.' '.$val.'. ';
		return $s;
	}
	
	private function WritePath($path, $StartPoint){
		$CurTown = $StartPoint;
		$CorrectPath = array();
		while (isset($path->$CurTown)) {
			$CorrectPath[] = $this->fromDataToString($path->$CurTown, $CurTown);
			$CurTown = $path->$CurTown->to;
		}
		return $CorrectPath;
	}
	
	public function GetPath($data) {
		$path = json_decode($data);
		$StartPoint = $this->GetStartPoint($path);
		$CorrectPath = $this->WritePath($path, $StartPoint);
		$CorrectPath = json_encode($CorrectPath);
		return $CorrectPath;
	}
	
}


?>