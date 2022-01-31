<meta http-equiv = "Content-Type" content = "text/html; charset = utf-8" />

<title>FAP</title>
<?php	
header('Content-Type: text/html; charset = utf-8');
mb_internal_encoding('utf-8');

include("connect.php");

// - ustawienie limitu czasu wykonywanej kwerendy na serwerze WAZNE!!! ze względu na wielkość pliku ładowanego z PERSUM
set_time_limit(300);

/**
*
* Klasa ListaPlikow tworzy listę plików z podanej lokalizacji 
*
* @param - $sciezka -> definiuje folder, w którym znajdują się pliki do listy
* @param - $listaPlikow -> właściwość klasy zawierająca pełna listę plików
*
*/
class ListaPlikow{
	public $listaPlikow;

	function __construct($sciezka){
		$this->listaPlikow = glob($sciezka,GLOB_NOSORT);
		array_multisort(array_map('filemtime', $this->listaPlikow), SORT_NUMERIC, SORT_ASC, $this->listaPlikow);
	}	
}

/**
*
* Klasa Plik zawiera informacje o pliku do zaimportowania 
*
* @param - $nazwa -> określa pełna lokazlizacje pliku wraz z jego nazwą,
* @param - $typ -> określa typ pliku,
* @param - $data -> data utworzenia pliku,
* @param - $zawartosc -> zawartosc pliku w postaci tablicy,
* @param - $iloscLinii -> ilość linii w pliku,
* @param - $zapytanieSQL -> przygotowane zapytanie na podstawie zawartości
*
*/
class Plik{

	public $nazwa;
	public $typ;
	public $data;
	public $zawartosc;
	public $iloscLinii;
	public $zapytanieSQL;
	
	function __construct($nazwa = null, $data = null){
		$this->nazwa = $nazwa;
		$this->data = $data;
  	}

	function PrzygotujZapytanie($plik) {
		$zapytanieSQL = "INSERT INTO odbicia2 (nr_ewi,rok,miesiac,data,odbicie,we_wy)";
		for ($i = $plik->iloscLinii; $i > -1; $i--){
			if (strlen($plik->zawartosc[$i])>5){
				$linia = $plik->zawartosc[$i];
				$nr_ewidencyjny = substr($linia,7,5);
				$rok = '20'.substr($linia,13,2);
				$miesiac = substr($linia,15,2);
				$dzien = substr($linia,17,2);
				$godzina_odbicia = substr($linia,19,4);
				$wejscie_wyjscie = substr($linia,23,2);
				$data = $rok."-".$miesiac."-".$dzien;
				if ($i > 0){
					$zapytanieSQL.=  " SELECT ".$nr_ewidencyjny.",".$rok.",".$miesiac.",'".$data."',".$godzina_odbicia.",".$wejscie_wyjscie." UNION ALL";
				}else{
					$zapytanieSQL.=  " SELECT ".$nr_ewidencyjny.",".$rok.",".$miesiac.",'".$data."',".$godzina_odbicia.",".$wejscie_wyjscie;
				}
			}
		$plik->zapytanieSQL = $zapytanieSQL;
		}
	}

	function ImportujPlik($plik, $polaczenie){
		
		$wykonajZapytanieSQL = odbc_exec($polaczenie,$plik->zapytanieSQL);
		odbc_exec($polaczenie,"INSERT INTO pliki (nazwa,data_pliku,ilosc_lini_w_pliku,ilosc_lini_zal,data_zal) VALUES ('".$plik->nazwa."','". Date('Y-m-d H:i:s',$plik->data)."','".$plik->iloscLinii."','".odbc_num_rows($wykonajZapytanieSQL)."','".date("Y-m-d H:i:s")."')");
	
		return odbc_num_rows($wykonajZapytanieSQL);
	}
}

class ImportujTXT extends Plik{

	function PrzygotujZapytanie($plik, $dataDzienna) {
		$zapytanieSQL = "INSERT INTO persum2 (ewi,imie,mpk,chr,kod,data,uspr,zaklad)";
		for ($i = $plik->iloscLinii; $i > -1; $i--){
			if (strlen($plik->zawartosc[$i])>5){
				$linia = $plik->zawartosc[$i];
				
				$tempImieNazwisko = substr($linia, 6 , 30);
				$tempImieNazwisko = explode(" ",$tempImieNazwisko);
				$tempImieNazwisko = array_filter($tempImieNazwisko);
				
				$numerEwidencyjny = substr($linia, 0, 5);
				
				$nazwisko = iconv("CP1250", "UTF-8",$tempImieNazwisko[0]);
				$nazwisko = str_replace("'", " ", $nazwisko);
				
				$imie = iconv("ISO-8859-2", "UTF-8", $tempImieNazwisko[1]);
				$imie = str_replace("'", " ", $imie);
				
				$mpk = substr($linia,37,6);
				$chr = substr($linia,44,1);
				$usprawiedliwienie = substr($linia,46,3);
				$data = substr($linia,50,10);
				$kodPracy = substr($linia,61,3);
				$zaklad = substr($linia,65,3);
				if (!is_numeric($usprawiedliwienie)){
					$usprawiedliwienie = '0';
				}
				$rok = substr($data,6,4);
				$miesiac = substr($data,3,2);
				$dzien = substr($data,0,2);
				$data = $rok."-".$miesiac."-".$dzien;
				if ($i > 0){ 
					$zapytanieSQL. = " SELECT ".$numerEwidencyjny.",N'".$nazwisko." ".$imie."','".$mpk."','".$chr."','".$kodPracy."','".$data."','".$usprawiedliwienie."','".$zaklad."' UNION ALL";
				}else{
					$zapytanieSQL. = " SELECT ".$numerEwidencyjny.",N'".$nazwisko." ".$imie."','".$mpk."','".$chr."','".$kodPracy."','".$data."','".$usprawiedliwienie."','".$zaklad."'";
				}
			}
		}
		$plik->zapytanieSQL = $zapytanieSQL;
	}

}

/**
*
* Klasa Daty ustawia wszystkie potrzebne daty służące do wykonania zapytań 
*
* @param - $polaczenie -> definiuje polaczenie z baza danych 
*
**/
class Data{
	public $dataDzis;
	public $miesiacObecny;
	public $rokObecny;
	public $wczoraj;
	public $miesiacPoprzedni;
	public $datazTBDzienna;
	public $datazTBMiesiac;

	function __construct($polaczenie){
		$this->dataDzis = date("Y-m-d");
		$this->miesiacObecny = date("n");
		$this->rokObecny = date("Y");
		$this->wczoraj = date("Y-m-d", strtotime( '-1 days' )); 
		$this->miesiacPoprzedni = date("n", strtotime( 'last day of -1 month' ));
		$this->datazTBDzienna = $this->DataDzienna($polaczenie);
		$this->datazTBMiesiac = $this->DataMiesiac($polaczenie);
	}
	
	function DataDzienna($polaczenie){
		$zapytanie = odbc_exec($polaczenie,'select distinct data from dzienna');
		while ($row = odbc_fetch_array($zapytanie)){
			return $row['data'];
		}
		return date("Y-m-d");
	}
	
	function DataMiesiac($polaczenie){
		$zapytanie = odbc_exec($polaczenie,'select distinct miesiac from miesiac');
		while ($row = odbc_fetch_array($zapytanie)){
			return $row['miesiac'];
		}
		return date("Y-m-d");
	}
}

/**
*
* Klasa Tabela zawiera metody służące do zarządzania tabelami - > przenoszenie danych, sprawdzanie ilość wierszy w tabeli, usuwanie danych oraz czyszczenie tabeli
*
* @param - $polaczenie -> definiuje polaczenie z baza danych 
* @param - $tabela -> definiuje tabele z której ma być wykoanana operacja
* @param - $dokąd -> definiuje tabele z której pobieramy dane
* @param - $kiedy -> definiuje datę dla której ma być wykonana operacja
* @param - $czas -> definiuje nazwę kolumny, której ma być wyszukany paramentr $kiedy
* @param - $operator -> definiuje operator matematyczny "<> = "
*
**/
class Tabela{

	/**
	*
	* Metoda - PrzeniesDane
	*
	* Przenosi dane pomiedzy tabelami dzienna, miesiac, rok
	*
	* @param - $tabela - > nazwa tabeli do której mają zostać dołączone dane
	* @param - $polaczenie -> z którego ma korzystać zapytanie
	* @return - ilosc linii załadowanych do param $tabela
	*
	**/

	function PrzeniesDane($tabela, $dokad, $polaczenie){
		$insert = odbc_exec($polaczenie,"INSERT INTO ".$dokad." (nr_ewi,imie_i_naz,komorka,chr,kchpr,usprawiedliwienie,odbicie_we,odbicie_wy,miesiac,rok,zmiana,obszar,ob_nb,przyczyna,bezp_pos_num,zaklad,data,inna,ilosc2,ZT) SELECT  nr_ewi,imie_i_naz,komorka,chr,kchpr,usprawiedliwienie,odbicie_we,odbicie_wy,miesiac,rok,zmiana,obszar,ob_nb,przyczyna,bezp_pos_num,zaklad,data,inna,ilosc2,ZT FROM ".$tabela);
		return odbc_num_rows($insert);
	}
	
	/**
	*
	* Metoda - SprawdzIloscWierszy
	*
	* Sprawdza ilość wierszy w podanej tabeli , gdzie kolumna =  data/miesiac
	*
	* @param - $tabela - > nazwa tabeli w której mają zostać policzone wiersze
	* @param - $polaczenie -> z którego ma korzystać zapytanie
	* @param - $kiedy -> data/miesiac dla której zostaną zliczone wiersze
	* @param - $czas -> nazwa kolumny w której ma być wyszukana data/miesiac
	* @return - ilosc linii załadowanych do param $tabela
	*
	**/
	function SprawdzIloscWierszy($tabela,$kiedy,$czas,$polaczenie){
		return odbc_num_rows(odbc_exec($polaczenie,"select * from ".$tabela." where ".$czas." = '".$kiedy."'"));
	}
	
	function UsunDane($tabela,$data,$operator,$polaczenie){
		odbc_exec ($polaczenie,"delete from ".$tabela." where data".$operator."'".$data."'");
		// echo "data from ".$tabela."have been removed <br>";
	}
	
	function WyczyscTabele($tabela, $polaczenie){
		//echo " ".$tabela." ".$polaczenie;
		odbc_exec($polaczenie,"TRUNCATE TABLE ".$tabela);
		// echo $tabela." cleared <br>";

	}
}

/**
*
* Klasa Odbicia rozszerza Tabele o dodanie danych z tabeli tymczasowej (do której ładowany jest jeden plik z odbiciami) odbicia2 do tabeli odbicia, które nie istnieją jeszcze w tabeli odbicia
*
* @param - $polaczenie -> definiuje polaczenie z baza danych 
*
**/
class Odbicia extends Tabela{
	function PrzeniesDane($polaczenie, $data_dzis){
		odbc_exec($polaczenie, "delete from odbicia where data<> '".$data_dzis."'");
		odbc_exec($polaczenie, "insert into odbicia (nr_ewi, odbicie, miesiac, rok, we_wy,data) select distinct odbicia2.nr_ewi, odbicia2.odbicie, odbicia2.miesiac, odbicia2.rok, odbicia2.we_wy,odbicia2.data from odbicia2 left join odbicia on (odbicia.nr_ewi = odbicia2.nr_ewi and odbicia.we_wy = odbicia2.we_wy) where odbicia.nr_ewi is null"); 
	}
}
/**
*
* Klasa OdbiciaArchiwum rozszerza Tabele o Przeniesienie danych z tabeli odbicia do archiwum odbić z datą starszą niż data istniejąca w tabeli dziennej
*
* @param - $datadzienna -> definiuje datę istniejącą w tabeli dzienna
* @param - $polaczenie -> definiuje polaczenie z baza danych 
*
**/
class OdbiciaArchiwum extends Tabela{
	function PrzeniesDane($dataDzienna,$polaczenie){
		odbc_exec($polaczenie,"insert into archiwum_odbic (nr_ewi,odbicie,miesiac,rok,we_wy,data) select distinct nr_ewi,odbicie,miesiac,rok,we_wy,data from odbicia where data<>'".$dataDzienna."'");
	}
}

/**
*
* Klasa Dzienna rozszerza Tabele o Przygotowanie Tabeli 
*
* @param - $dataDzis,$miesiacObecny,$rokObecny 
*
**/
class Dzienna extends Tabela{

	function PrzygotujDzienna($polaczenie){
		odbc_exec($polaczenie,"INSERT INTO dzienna (nr_ewi,imie_i_naz,komorka,chr,kchpr,data,zaklad,usprawiedliwienie) SELECT distinct ewi,imie,mpk,chr,kod,data,zaklad,uspr FROM persum2");
	}
}

/**
*
* Klasa Update zawiera metody do aktualizacji tabeli dziennej, miesięcznej oraz odbicia
*
* @param - $dataDzis -> definiuje datę dzisiejszą z klasy  
* @param - $polaczenie -> definiuje polaczenie z baza danych 
*
**/


class Update {

	function UpdateDzienna($dataDzis,$polaczenie){
		// --- ustawienie roku i miesiaca w tabeli dziennej
		odbc_exec($polaczenie,"UPDATE dzienna set rok = Year(data), miesiac = month(data)");
		// --- ustawienie obszaru w tabeli dziennej
		odbc_exec($polaczenie,'UPDATE dzienna SET dzienna.obszar = obszar.RAPORT,dzienna.ZT = obszar.kod from dzienna INNER JOIN obszar ON dzienna.komorka = obszar.komorka');
		// --- update odbic w tabeli dziennej
		odbc_exec($polaczenie,"UPDATE dzienna set odbicie_wy = null where odbicie_wy = '     '");
		odbc_exec($polaczenie,"UPDATE dzienna set odbicie_we = null where odbicie_we = '     '");
		odbc_exec($polaczenie,"UPDATE dzienna set odbicie_wy = ('0'+odbicie_wy) where len(odbicie_wy)<4 and odbicie_wy is not null and odbicie_wy<>'     '");
		odbc_exec($polaczenie,"UPDATE dzienna set odbicie_wy = (left(odbicie_wy,2)+':'+right(odbicie_wy,3)) where len(odbicie_wy)<5 and odbicie_wy is not null and odbicie_wy<>'     '");
		odbc_exec($polaczenie,"UPDATE dzienna SET dzienna.odbicie_we = odbicia.odbicie,dzienna.ob_nb = 'O',przyczyna = 'Obecny',usprawiedliwienie = '700' from dzienna INNER JOIN odbicia ON dzienna.nr_ewi =  odbicia.nr_ewi where odbicia.we_wy = '0' and odbicia.data = dzienna.data and (odbicie_we is null or odbicie_we = '')");
		odbc_exec($polaczenie,"UPDATE dzienna SET dzienna.odbicie_wy = odbicia.odbicie from dzienna INNER JOIN odbicia ON dzienna.nr_ewi =  odbicia.nr_ewi where odbicia.we_wy = '1' and odbicia.data = dzienna.data and dzienna.odbicie_we is not null");
		// odbc_exec($polaczenie,"UPDATE dzienna SET dzienna.odbicie_wy = odbicia.odbicie from dzienna INNER JOIN odbicia ON dzienna.nr_ewi =  odbicia.nr_ewi where odbicia.we_wy = '1' and odbicia.data = dzienna.data and dzienna.odbicie_we is not null and CONVERT(int,dzienna.odbicie_we)<CONVERT(int,odbicia.odbicie)");
		// --- ustawienie typu pracownika w tabeli dziennej
		odbc_exec($polaczenie,"UPDATE dzienna SET bezp_pos_num = 'POSR.PROD.' WHERE chr = '1'");
		odbc_exec($polaczenie,"UPDATE dzienna SET bezp_pos_num = 'BEZP.PROD.' WHERE chr = '2'");
		odbc_exec($polaczenie,"UPDATE dzienna SET bezp_pos_num = 'PRAC.UMYS.' WHERE chr<>'1' AND chr<>'2'");
		// --- ustawienie przyczyna obecności/nieobecności w tabeli dziennej 	
		odbc_exec($polaczenie,"UPDATE dzienna SET dzienna.przyczyna = kody_uspr.opis,dzienna.ob_nb = kody_uspr.O_N from dzienna INNER JOIN kody_uspr ON dzienna.usprawiedliwienie = kody_uspr.kod where dzienna.usprawiedliwienie<>'0'");
		// odbc_exec($connection,"UPDATE dzienna SET dzienna.przyczyna = kody_uspr.opis from dzienna INNER JOIN kody_uspr ON dzienna.usprawiedliwienie =  kody_uspr.kod");
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = NULL, przyczyna = NULL WHERE usprawiedliwienie = '0'");		
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = 'W', przyczyna  = 'Z_WOLNE',zmiana = '1',usprawiedliwienie = '703' where (kchpr = '900' or kchpr = '901' or kchpr = '902') and odbicie_we is null and usprawiedliwienie = '0'");
		odbc_exec($polaczenie,"UPDATE dzienna set usprawiedliwienie = '701',przyczyna  = 'Zwiazki',ob_nb = 'O',zmiana = '1',obszar = 'ZWIAZKI' where (ZAKLAD = '175' OR ZAKLAD = '158') and komorka like '3%'  and odbicie_we is null ");
		odbc_exec($polaczenie,'UPDATE dzienna set usprawiedliwienie = "702",przyczyna  = "Obecny",ob_nb = "O" where ob_nb is null and komorka like "99%"');
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = NULL, przyczyna = NULL WHERE usprawiedliwienie = '0'");
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = 'O', przyczyna = 'Obecny',usprawiedliwienie = '700' WHERE odbicie_we is not null");
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = 'O', przyczyna = 'Obecny',usprawiedliwienie = '700' WHERE  kchpr = '888'");
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = 'O', przyczyna = 'Obecny',usprawiedliwienie = '700' FROM dzienna where (zaklad = '158' or zaklad = '175') and ob_nb is null and bezp_pos_num = 'PRAC.UMYS.'");
		odbc_exec($polaczenie,"UPDATE dzienna SET ob_nb = 'O', przyczyna = 'Obecny',usprawiedliwienie = '700' WHERE (kchpr = '930' or kchpr = '931' or kchpr = '932') and odbicie_we is not null");
		// --- ustawienie numeru zmian w tabeli dziennej
		odbc_exec($polaczenie,'UPDATE dzienna SET dzienna.zmiana = kody_persum.Zm from dzienna INNER JOIN kody_persum ON dzienna.kchpr = kody_persum.kod where dzienna.inna IS NULL ');
		odbc_exec($polaczenie,"UPDATE dzienna SET ZMIANA = ODDELEGOWANIA.ZAMIANA_ZMIAN, ilosc2 = oddelegowania.ilosc, INNA = 'ZAMIANA ZMIAN' from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi where  oddelegowania.do1> = '".$dataDzis."' and oddelegowania.od< = '".$dataDzis."' AND ODDELEGOWANIA.TYP = 'ZAMIANA ZMIAN'");
		odbc_exec($polaczenie,"UPDATE dzienna SET ZMIANA = '1' FROM dzienna WHERE LEFT(ODBICIE_WE,2)<13 AND OB_NB = 'O' AND KCHPR LIKE '90%'");		
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '1',inna = 'INNA ZMIANA' WHERE (zmiana = '2' and Left(odbicie_we,2) = '05') or (zmiana = '2' and Left(odbicie_we,2) = '06') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi where data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '1',inna = 'INNA ZMIANA' WHERE (zmiana = '3' and Left(odbicie_we,2) = '05') or (zmiana = '3' and Left(odbicie_we,2) = '06') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi WHERE data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '2',inna = 'INNA ZMIANA' WHERE (zmiana = '1' and Left(odbicie_we,2) = '13') or (zmiana = '1' and Left(odbicie_we,2) = '14') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi WHERE data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '2',inna = 'INNA ZMIANA' WHERE (zmiana = '3' and Left(odbicie_we,2) = '13') or (zmiana = '3' and Left(odbicie_we,2) = '14') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi WHERE data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '3',inna = 'INNA ZMIANA' WHERE (zmiana = '1' and Left(odbicie_we,2) = '21') or (zmiana = '1' and Left(odbicie_we,2) = '22') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi WHERE data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");
		odbc_exec($polaczenie,"UPDATE dzienna set zmiana = '3',inna = 'INNA ZMIANA' WHERE (zmiana = '2' and Left(odbicie_we,2) = '21') or (zmiana = '3' and Left(odbicie_we,2) = '22') and inna is null AND NR_EWI NOT IN (select dzienna.nr_ewi from dzienna inner join oddelegowania on dzienna.nr_ewi = oddelegowania.nr_ewi WHERE data = '".$dataDzis."' and oddelegowania.do1> =  '".$dataDzis."' and oddelegowania.od< =  '".$dataDzis."' and (oddelegowania.kzo<>oddelegowania.kzo_stary or (kzo = kzo_stary and typ not in ('POMIEDZY KZT','DO KZO', 'DO KZT'))) and oddelegowania.typ<>'zamiana zmian' and bezp_pos_num = 'BEZP.PROD.')");

		odbc_exec($connection,"update dzienna set obszar = 'BUD.TL.' where zaklad = '175'");
		// echo "update dzienna <br>";
	}
	
	function UpdateMiesiac($polaczenie){
		odbc_exec($polaczenie,"update miesiac set rok = Year(data), miesiac = month(data)");
		odbc_exec($polaczenie,'update miesiac SET miesiac.odbicie_wy = odbicia.odbicie from miesiac INNER JOIN odbicia ON miesiac.nr_ewi =  odbicia.nr_ewi where odbicia.we_wy = "1" and Convert(int,right(miesiac.data,2)) = DAY(GETDATE())-1 and miesiac.odbicie_wy is null and miesiac.odbicie_we is not null and LEN(odbicia.odbicie)<4');
		odbc_exec($polaczenie,'update miesiac SET miesiac.odbicie_wy = archiwum_odbic.odbicie from miesiac INNER JOIN archiwum_odbic ON miesiac.nr_ewi =  archiwum_odbic.nr_ewi where archiwum_odbic.we_wy = "1" and Convert(int,right(miesiac.data,2)) = DAY(GETDATE())-1 and miesiac.odbicie_wy is null and miesiac.odbicie_we is not null and LEN(archiwum_odbic.odbicie)<4');
		odbc_exec($polaczenie,'update miesiac SET miesiac.odbicie_we = archiwum_odbic.odbicie from miesiac INNER JOIN archiwum_odbic ON miesiac.nr_ewi =  archiwum_odbic.nr_ewi and miesiac.data = archiwum_odbic.data and archiwum_odbic.we_wy = "0"');
		odbc_exec($polaczenie,'update miesiac SET miesiac.odbicie_wy = odbicia.odbicie,usprawiedliwienie = "700",ob_nb = "O" from miesiac INNER JOIN odbicia ON miesiac.nr_ewi =  odbicia.nr_ewi where odbicia.we_wy = "1" and miesiac.data = odbicia.data  and miesiac.odbicie_wy is null and miesiac.odbicie_we is not null');
		odbc_exec($polaczenie,'update miesiac SET miesiac.odbicie_we = odbicia.odbicie,usprawiedliwienie = "700",ob_nb = "O"  from miesiac INNER JOIN odbicia ON miesiac.nr_ewi =  odbicia.nr_ewi and miesiac.data = odbicia.data and odbicia.we_wy = "0"');
		// echo "update Miesiac <br>";
	}
	
	function UpdateOdbicia($polaczenie){
		odbc_exec($polaczenie,"update odbicia set odbicie = ('0'+odbicie) where len(odbicie)<4 and odbicie is not null");	
		odbc_exec($polaczenie,"update odbicia set odbicie = (left(odbicie,2)+':'+right(odbicie,3)) where len(odbicie)<5 and odbicie is not null");
		// echo "update odbicia <br>";
	
	}
}

/**
*
* Ustawienie dat
*
**/

$data = new Data($connection);
$data_dzienna_tb = $data->datazTBDzienna;
$data_dzis = $data->dataDzis;
$data_wczoraj = $data->wczoraj;
$miesiac_obecny = $data->miesiacObecny;
$miesiac_poprzedni = $data->miesiacPoprzedni;
$data_miesiac_tb = $data->datazTBMiesiac;
$rok_obecny = $data->rokObecny;


echo "dzienna ".$data_dzienna_tb."<br>";
echo "miesiac ".$data_miesiac_tb."<br>";


/**
*
* Importowanie pliku
*
**/
$sciezka = "F:/aplikacjeFAP/HR_REPORT_IN/26_27_09_2015/*.txt";
$tabela_ogolna = new Tabela;
$dzienna = new Dzienna;
$odbicia = new Odbicia;
$odbicia_archiwum = new OdbiciaArchiwum;
$update = new Update;

$lista_plikow = new ListaPlikow($sciezka);

$pliki_do_zaimportowania = array();
foreach ($lista_plikow as $key = >$value){
	foreach ($value as $item){


		$czy_istnieje = odbc_num_rows(odbc_exec($connection,"SELECT * FROM pliki WHERE nazwa = '".$item."'"));
	
		if ($czy_istnieje = = = 0){
			$plik = new Plik($item, filemtime($item));
			$plik->zawartosc = file($item);
			$plik->iloscLinii = count($plik->zawartosc);
			$elementy_linii = explode(" ",$plik->zawartosc[0]);
			if (count($elementy_linii)>2){
				$plik->typ = 'txt';
				$plikTxt = new ImportujTXT;
				$plikTxt -> PrzygotujZapytanie($plik, ""); //-----przygotowanie zapytaniaSQL
				$tabela_ogolna -> WyczyscTabele("persum2",$connection);
				$importuj_txt = $plik -> ImportujPlik($plik, $connection);
				if ($importuj_txt>0){
					$data_dzienna_tb = $data->DataDzienna($connection);

					if ($data_miesiac_tb = =  intval(date("n",strtotime($data_dzienna_tb)))){
						//---- tworzenie tabeli dziennej w srodku miesiaca

						//- -- 1) jezeli istnieja delete pozycji tabeli dziennej z miesiaca

						$tabela_ogolna -> UsunDane("miesiac", $data_dzienna_tb," = ", $connection);
						//- -- 2) przeniesienie dziennej do miesiaca
						$przenies_dzienna_do_miesiac = $tabela_ogolna->PrzeniesDane("dzienna", "miesiac", $connection);
						//-----4) sprawdzenie czy wszystkie wiersze sie przeniosły
						$sprawdz_ilosc_wierszy_dziennej = $tabela_ogolna -> SprawdzIloscWierszy("dzienna",$data_dzienna_tb,"data",$connection);
						if ($przenies_dzienna_do_miesiac = = = $sprawdz_ilosc_wierszy_dziennej){
							//---- 5a) przenies dane z tabeli dzienna do dzienna_archiwum
							$przenies_do_dzienna_archiwum = $tabela_ogolna -> PrzeniesDane("dzienna","dzienna_archiwum", $connection);
							//---- 5b) czyszczenie tabeli dziennej
							$tabela_ogolna -> WyczyscTabele("dzienna",$connection);
							//---- 6) przeniesienie danych z tabeli persum2 do tabeli dziennej
							$przygotuj_dzienna = $dzienna -> PrzygotujDzienna($connection);
							//---- 7) update tabeli dziennej i uzupełnienie danych
							$update->UpdateDzienna($data_dzis,$connection);

						}
					}else{
						// ---- tworzenie tabeli dziennej na poczatku miesiaca
						//---- 1) przeniesienie tabeli miesiac do rok
						$przenies_miesiac_do_rok = $tabela_ogolna -> PrzeniesDane("miesiac","rok",$connection);
						
						//---- 2) sprawdzenie czy wszystkie wiersze sie przeniosły
						$sprawdz_ilosc_wierszy_miesiac = $tabela_ogolna -> SprawdzIloscWierszy("miesiac", $data_miesiac_tb, "miesiac", $connection);
						//echo "ilość miesiac do rok ".$przenies_miesiac_do_rok." ilość wierszy miesiac ".$sprawdz_ilosc_wierszy_miesiac."<br>";
						if ($przenies_miesiac_do_rok = = = $sprawdz_ilosc_wierszy_miesiac){
							//echo "jestem 1<br>";
							//---- 3) czyszczenie tabeli miesiac
							$tabela_ogolna -> WyczyscTabele("miesiac", $connection);
							//- -- 4) przeniesienie dziennej do miesiaca
							$przenies_dzienna_do_miesiac = $tabela_ogolna -> PrzeniesDane("dzienna", "miesiac", $connection);
							//-----5) sprawdzenie czy wszystkie wiersze sie przeniosły
							$sprawdz_ilosc_wierszy_dziennej = $tabela_ogolna -> SprawdzIloscWierszy("dzienna", $data_dzienna_tb, "data", $connection);
							
							if ($przenies_dzienna_do_miesiac = = = $sprawdz_ilosc_wierszy_dziennej){
								//echo "jestem 2<br>";
								//---- 6a) przenies dane z tabeli dzienna do dzienna_archiwum
								$przenies_do_dzienna_archiwum = $tabela_ogolna -> PrzeniesDane("dzienna","dzienna_archiwum", $connection);
								//---- 6b) czyszczenie tabeli dziennej
								$tabela_ogolna -> WyczyscTabele("dzienna",$connection);
								//---- 7) przeniesienie danych z tabeli persum2 do tabeli dziennej
								$dzienna -> PrzygotujDzienna($connection);

								//---- 8) update tabeli dziennej i uzupełnienie danych
								$update->UpdateDzienna($data_dzis,$connection);
							}
						}
					}
				}
			}else{
				$plik->typ = 'numer';
				$plik->PrzygotujZapytanie($plik);
				$importuj_numer = $plik -> ImportujPlik($plik, $connection);

				if ($importuj_numer > 0){
					//--- 1) sprawdzenie aktualnej daty dziennej
					$data_dzienna_check = $data->DataDzienna($connection);
					//--- 2) przeniesienie odbic z tabeli odbicia2 do odbicia
					$przenies_odbicia = $odbicia -> PrzeniesDane($connection,$data_dzienna_check);	
					//--- 3) zaktualizowane odbić o : pomiedzy godziną						
					$update -> UpdateOdbicia($connection);
					//--- 4) zaktualizowane dziennej o odbicia
					$update -> UpdateDzienna($data_dzienna_check,$connection);
					//--- 5) zaktualizowane miesiaca o odbicia wyjscia
					$update -> UpdateMiesiac($connection);
					//--- 6) przeniesienie odbic z datą różną od daty z tabeli dziennej do archiwum
					$odbicia_archiwum -> PrzeniesDane($data_dzienna_tb, $connection);
					//--- 7) czyszczenie tabeli tymczasowej "odbicia2" zawierającej odbicia z pojedynczego pliku 
					$tabela_ogolna -> WyczyscTabele("odbicia2", $connection);
				}
			}
			array_push($pliki_do_zaimportowania, $plik);
		}
	}
}
// var_dump($pliki_do_zaimportowania);

?>