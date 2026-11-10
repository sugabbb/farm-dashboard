import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Alert, Button, Modal } from 'react-bootstrap';
import { DateField } from './FormControls';

export function ExportRangeModal({show,onHide,endpoint,today,title,filters={}}:{show:boolean;onHide:()=>void;endpoint:string;today:string;title:string;filters?:Record<string,string>}){
  const [from,setFrom]=useState(`${today.slice(0,7)}-01`);
  const [to,setTo]=useState(today);
  useEffect(()=>{if(show){setFrom(`${today.slice(0,7)}-01`);setTo(today);}},[show,today]);
  const valid=Boolean(from&&to&&from<=to);
  const url=valid?`${endpoint}?${new URLSearchParams({from,to,...Object.fromEntries(Object.entries(filters).filter(([,value])=>value))}).toString()}`:undefined;
  return <Modal show={show} onHide={onHide} centered>
    <Modal.Header closeButton><Modal.Title>{title}</Modal.Title></Modal.Header>
    <Modal.Body><p className="text-muted">Pilih periode khusus untuk workbook.{Object.values(filters).some(Boolean)?' Filter daftar yang aktif tetap diterapkan.':' Periode ini tidak mengikuti filter daftar transaksi.'}</p><div className="form-grid"><DateField label="Dari" value={from} onChange={setFrom} max={today} required/><DateField label="Sampai" value={to} onChange={setTo} min={from} max={today} required/></div>{from&&to&&from>to&&<Alert variant="danger" className="mt-3 mb-0">Tanggal Sampai tidak boleh sebelum tanggal Dari.</Alert>}</Modal.Body>
    <Modal.Footer><Button variant="light" onClick={onHide}>Batal</Button><Button as="a" href={url} disabled={!valid}><Download/> Download Excel</Button></Modal.Footer>
  </Modal>;
}
