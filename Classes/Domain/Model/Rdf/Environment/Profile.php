<?php
declare(ENCODING = 'utf-8') ;
namespace Erfurt\Domain\Model\Rdf\Environment;

/*                                                                        *
 * This script belongs to the Erfurt framework.                           *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License as published by the Free   *
 * Software Foundation, either version 2 of the License, or (at your      *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/copyleft/gpl.html.                      *
 *                                                                        */

/**
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class Profile implements ProfileInterface {

	/**
	 * @var \Erfurt\Domain\Model\Rdf\Environment\PrefixMap
	 * @inject
	 */
	protected $prefixes;

	public function getPrefixes() {
		return $this->prefixes;
	}

	public function resolve($curieOrTerm) {
		return $this->prefixes->resolve($curieOrTerm);
	}

	public function setPrefix($prefix, $iri) {
		$this->prefixes->set($prefix, $iri);
	}
}
?>