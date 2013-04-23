<?php
/**
 * Class that represents a single "component" of a page - either a template
 * or a piece of free text.
 *
 * @author Yaron Koren
 */
class DTPageComponent {
	var $mIsTemplate = false;
	var $mTemplateName;
	static $mUnnamedFieldCounter;
	var $mFields;
	var $mFreeText;
	static $mFreeTextIDCounter = 1;
	var $mFreeTextID;

	public static function newTemplate( $templateName ) {
		$dtPageComponent = new DTPageComponent();
		$dtPageComponent->mTemplateName = $templateName;
		$dtPageComponent->mIsTemplate = true;
		$dtPageComponent->mFields = array();
		self::$mUnnamedFieldCounter = 1;
		return $dtPageComponent;
	}
	public static function newFreeText( $freeText ) {
		$dtPageComponent = new DTPageComponent();
		$dtPageComponent->mIsTemplate = false;
		$dtPageComponent->mFreeText = $freeText;
		$dtPageComponent->mFreeTextID = self::$mFreeTextIDCounter++;
		return $dtPageComponent;
	}

	public function addNamedField( $fieldName, $fieldValue ) {
		$this->mFields[trim( $fieldName )] = trim( $fieldValue );
	}

	public function addUnnamedField( $fieldValue ) {
		$fieldName = self::$mUnnamedFieldCounter++;
		$this->mFields[$fieldName] = trim( $fieldValue );
	}

	public function toXML( $isSimplified ) {
		if ( $this->mIsTemplate ) {
			global $wgContLang;
			$namespace_labels = $wgContLang->getNamespaces();
			$template_label = $namespace_labels[NS_TEMPLATE];
			$field_str = str_replace( ' ', '_', wfMessage( 'dt_xml_field' )->inContentLanguage()->text() );
			$name_str = str_replace( ' ', '_', wfMessage( 'dt_xml_name' )->inContentLanguage()->text() );

			$bodyXML = '';
			foreach ( $this->mFields as $fieldName => $fieldValue ) {
				if ( is_numeric( $fieldName ) ) {
					if ( $isSimplified ) {
						// add "Field" to the beginning of the file name, since
						// XML tags that are simply numbers aren't allowed
						$bodyXML .= Xml::element( $field_str . '_' . $fieldName, null, $fieldValue );
					} else {
						$bodyXML .= Xml::element( $field_str, array( $name_str => $fieldName ), $fieldValue );
					}
				} else {
					if ( $isSimplified ) {
						$fieldName = str_replace( ' ', '_', trim( $fieldName ) );
						$bodyXML .= Xml::element( $fieldName, null, $fieldValue );
					} else {
						$bodyXML .= Xml::element( $field_str, array( $name_str => $fieldName ) , $fieldValue );
					}
				}
			}

			if ( $isSimplified ) {
				$templateName = str_replace( ' ', '_', $this->mTemplateName );
				return Xml::tags( $templateName, null, $bodyXML );
			} else {
				return Xml::tags( $template_label, array( $name_str => $this->mTemplateName ), $bodyXML );
			}
		} else {
			$free_text_str = str_replace( ' ', '_', wfMessage( 'dt_xml_freetext' )->inContentLanguage()->text() );
			return XML::element( $free_text_str, array( 'id' => $this->mFreeTextID ), $this->mFreeText );
		}
	}
}

/**
 * Class that holds the structure of a single wiki page.
 *
 * @author Yaron Koren
 */
class DTPageStructure {
	var $mPageTitle;
	var $mComponents = array();

	function addComponent( $dtPageComponent ) {
		$this->mComponents[] = $dtPageComponent;
		DTPageComponent::$mFreeTextIDCounter = 1;
	}

	public function newFromTitle( $pageTitle ) {
		$pageStructure = new DTPageStructure();
		$pageStructure->mPageTitle = $pageTitle;

		if ( method_exists( 'WikiPage', 'getContent' ) ) {
			$wiki_page = new WikiPage( $pageTitle );
			$page_contents = $wiki_page->getContent()->getNativeData();
		} else {
			$article = new Article( $pageTitle );
			$page_contents = $article->getContent();
		}

		// escape out variables like "{{PAGENAME}}"
		$page_contents = str_replace( '{{PAGENAME}}', '&#123;&#123;PAGENAME&#125;&#125;', $page_contents );
		// escape out parser functions
		$page_contents = preg_replace( '/{{(#.+)}}/', '&#123;&#123;$1&#125;&#125;', $page_contents );
		// escape out transclusions, and calls like "DEFAULTSORT"
		$page_contents = preg_replace( '/{{(.*:.+)}}/', '&#123;&#123;$1&#125;&#125;', $page_contents );
		// escape out variable names
		$page_contents = str_replace( '{{{', '&#123;&#123;&#123;', $page_contents );
		$page_contents = str_replace( '}}}', '&#125;&#125;&#125;', $page_contents );
		// escape out tables
		$page_contents = str_replace( '{|', '&#123;|', $page_contents );
		$page_contents = str_replace( '|}', '|&#125;', $page_contents );

		// traverse the page contents, one character at a time
		$uncompleted_curly_brackets = 0;
		$free_text = "";
		$template_name = "";
		$field_name = "";
		$field_value = "";
		$field_has_name = false;
		for ( $i = 0; $i < strlen( $page_contents ); $i++ ) {
			$c = $page_contents[$i];
			if ( $uncompleted_curly_brackets == 0 ) {
				if ( $c == "{" || $i == strlen( $page_contents ) - 1 ) {
					if ( $i == strlen( $page_contents ) - 1 )
						$free_text .= $c;
					$uncompleted_curly_brackets++;
					$free_text = trim( $free_text );
					if ( $free_text != "" ) {
						$freeTextComponent = DTPageComponent::newFreeText( $free_text );
						$pageStructure->addComponent( $freeTextComponent );
						$free_text = "";
					}
				} elseif ( $c == "{" ) {
					// do nothing
				} else {
					$free_text .= $c;
				}
			} elseif ( $uncompleted_curly_brackets == 1 ) {
				if ( $c == "{" ) {
					$uncompleted_curly_brackets++;
					$creating_template_name = true;
				} elseif ( $c == "}" ) {
					$uncompleted_curly_brackets--;
					// is this needed?
					// if ($field_name != "") {
					//	$field_name = "";
					// }
					if ( $page_contents[$i - 1] == '}' ) {
						$pageStructure->addComponent( $curTemplate );
					}
					$template_name = "";
				}
			} else { // 2 or greater - probably 2
				if ( $c == "}" ) {
					$uncompleted_curly_brackets--;
				}
				if ( $c == "{" ) {
					$uncompleted_curly_brackets++;
				} else {
					if ( $creating_template_name ) {
						if ( $c == "|" || $c == "}" ) {
							$curTemplate = DTPageComponent::newTemplate( $template_name );
							$template_name = str_replace( ' ', '_', trim( $template_name ) );
							$template_name = str_replace( '&', '&amp;', $template_name );
							$creating_template_name = false;
							$creating_field_name = true;
							$field_id = 1;
						} else {
							$template_name .= $c;
						}
					} else {
						if ( $c == "|" || $c == "}" ) {
							if ( $field_has_name ) {
								$curTemplate->addNamedField( $field_name, $field_value );
								$field_value = "";
								$field_has_name = false;
							} else {
								// "field_name" is actually the value
								$curTemplate->addUnnamedField( $field_name );
							}
							$creating_field_name = true;
							$field_name = "";
						} elseif ( $c == "=" ) {
							// handle case of = in value
							if ( ! $creating_field_name ) {
								$field_value .= $c;
							} else {
								$creating_field_name = false;
								$field_has_name = true;
							}
						} elseif ( $creating_field_name ) {
							$field_name .= $c;
						} else {
							$field_value .= $c;
						}
					}
				}
			}
		}
		return $pageStructure;
	}

	public function toXML( $isSimplified ) {
		$page_str = str_replace( ' ', '_', wfMessage( 'dt_xml_page' )->inContentLanguage()->text() );
		$id_str = str_replace( ' ', '_', wfMessage( 'dt_xml_id' )->inContentLanguage()->text() );
		$title_str = str_replace( ' ', '_', wfMessage( 'dt_xml_title' )->inContentLanguage()->text() );

		$bodyXML = '';
		foreach ( $this->mComponents as $pageComponent ) {
			$bodyXML .= $pageComponent->toXML( $isSimplified );
		}
		$articleID = $this->mPageTitle->getArticleID();
		$pageName = $this->mPageTitle->getText();
		if ( $isSimplified ) {
			return Xml::tags( $page_str, null, Xml::tags( $id_str, null, $articleID ) . Xml::tags( $title_str, null, $pageName ) . $bodyXML );
		} else {
			return Xml::tags( $page_str, array( $id_str => $articleID, $title_str => $pageName ), $bodyXML );
		}
	}

}
