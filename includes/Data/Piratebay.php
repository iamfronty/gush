<?php

class Data_Piratebay extends DataUpstream {

	private $sortFields = array(
			self::SORT_SEEDS => 7,
			self::SORT_LEECH => 9,
			self::SORT_SIZE => 5,
			self::SORT_AGE => 3
	);

	public function getData($query, $page = 0) {
		$url = $this->buildUrl($query, $page);
		$response = $this->retreiveData($url, self::FORMAT_PLAIN);
		if(strpos($response, 'No hits.') > 0) return array();
		return $this->parseResponse($response);
	}

	private function parseResponse($html) {
		$dom = new Zend_Dom_Query($html);
		$results = $dom->query('tr');

		$i = 0;
		foreach($results as $parentElement) {
			$newHTML = $this->nodeToHTML($parentElement);
			$subDom = new Zend_Dom_Query($newHTML);
			$subResults = $subDom->query('td');
			$parts = array();
			foreach($subResults as $subResult) {
				foreach($subResult->childNodes as $childNode) {
					$tmpHTML = str_replace(array("\t", "\n"), '', trim($this->nodeToHTML($childNode)));
					if($tmpHTML || $tmpHTML === '0') $parts[] = $tmpHTML;
				}
			}
			if(count($parts) && $i) $row[] = $parts;
			$i++;
		}

		$data = array();
		foreach($row as $item) {
			$infoLinkParts = explode('/', $this->getAttributeFromHTML($item[1], 'a', 'href'));
			print_r($item);
			$totalFields = count($item);
			$itemData = array();
			$itemData['name'] = $this->getTextBetweenTags($item[1], 'a');
			$itemData['magnet'] = $this->getAttributeFromHTML($item[2], 'a', 'href');
			$itemData['seeds'] = (int) $item[($totalFields - 2)];
			$itemData['peers'] = (int) $item[($totalFields - 1)];
			$sizeParts = explode(',', $item[($totalFields - 3)]);
			$itemData['size'] = $this->convertFileSize($sizeParts[1]);
			
			$itemData['hash'] = $this->magnetToHash($itemData['magnet']);
			$itemData['magnetParts'] = $this->parseMagnetLink($itemData['magnet']);
			if(stripos($item[3], 'comment')) {
				$itemData['comments'] = preg_replace("/[^0-9]/","", $this->getAttributeFromHTML($item[3], 'img', 'alt'));
			}
			$itemData['metadata']['Piratebay']['id'] = $infoLinkParts[2];
			
			$data[] = $itemData;
		}
		return $data;
	}
	
	public function getTorrentMeta($torrentId) {
		$meta = array(
			'comments' => $this->getComments($torrentId),
			'files' => $this->getFileListing($torrentId)
		);
		return $meta;
	}
	
	private function getComments($torrentId) {
		$url = 'https://thepiratebay.sx/ajax_details_comments.php';
		$post = array(
			'id' => $torrentId,
			'page' => '1'
		);
		$data = $this->retreiveData($url, self::FORMAT_PLAIN, $post);
		$dom = new Zend_Dom_Query($data);
		$results = $dom->query('div[id*="comment"] .comment');
		$comments = array();
		foreach($results as $parentElement) {
			$newHTML = $this->nodeToHTML($parentElement);
			$comments[] = $this->cleanString($newHTML);
		}
		return $comments;
	}
	
	private function getFileListing($torrentId) {
		$url = 'https://thepiratebay.sx/ajax_details_filelist.php?id='. $torrentId;
		$data = $this->retreiveData($url, self::FORMAT_PLAIN);
		$dom = new Zend_Dom_Query($data);
		$results = $dom->query('tr');
		$row = array();

		foreach($results as $parentElement) {
			$newHTML = $this->nodeToHTML($parentElement);
			$subDom = new Zend_Dom_Query($newHTML);
			$subResults = $subDom->query('td');
			$parts = array();
			foreach($subResults as $subResult) {
				foreach($subResult->childNodes as $childNode) {
					$tmpHTML = str_replace(array("\t", "\n"), '', trim($this->nodeToHTML($childNode)));
					if($tmpHTML || $tmpHTML === '0') $parts[] = $tmpHTML;
				}
			}
			if(count($parts)) $row[] = $parts;
		}

		$output = array();
		foreach($row as $file) {
			$output[] = array(
				'filename' => $file[0],
				'size' => $this->convertFileSize($file[1])
			);
		}
		return $output;
	}
	
	private function convertFileSize($size) {
		$fileSize = str_replace(array('Size ', '&nbsp;'), '', trim($size));
		switch(strtolower(substr($fileSize, -3))) {
			case 'kib':
				$itemSize = (float) substr($fileSize, 0, (strlen($fileSize - 3))) * 1024;
				break;
			case 'mib':
				$itemSize = ((float) substr($fileSize, 0, (strlen($fileSize - 3))) * (1024 * 1024));
				break;
			case 'gib':
				$size = (float) substr($fileSize, 0, (strlen($fileSize - 3)));
				$itemSize = $size * (1024 * (1024 * 1024));
				break;
		}
		return $this->formatBytes($itemSize);
	}

	private function buildUrl($query, $page = 0, $sortField = self::SORT_SEEDS, $sortOrder = self::SORT_DESC) {
		$sortValue = ($this->sortFields[$sortField] + $sortOrder);
		$url = 'https://thepiratebay.sx/search/' . urlencode($query) . '/' . $page . '/' . $sortValue;
		return $url;
	}
}