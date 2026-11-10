import { ChevronDown, History } from 'lucide-react';
import { dateTime } from '../utils';

export type AuditChange={field:string;label:string;before:string;after:string};
export type AuditEntry={id:number;action:string;label:string;actor_name:string;happened_at:string;reason:string|null;subject_label:string|null;changes:AuditChange[]};

export function AuditTimeline({logs}:{logs:AuditEntry[]}){
 return <section className="audit-section"><div className="section-title"><History size={17}/><span>Audit Log</span></div>{logs.length?<div className="audit-timeline">{logs.map(log=><article className="audit-event" key={log.id}><div className="audit-marker"/><div className="audit-event-body"><div className="audit-event-heading"><div><strong>{log.label}</strong>{log.subject_label&&<span className="audit-subject">{log.subject_label}</span>}</div><time>{dateTime(log.happened_at)}</time></div><div className="small text-muted">{log.actor_name}</div>{log.reason&&<p className="audit-reason">{log.reason}</p>}{log.changes.length>0&&<details><summary><ChevronDown size={14}/> Lihat perubahan ({log.changes.length})</summary><div className="audit-changes">{log.changes.map(change=><div className="audit-change" key={`${log.id}-${change.field}`}><span>{change.label}</span><div><del>{change.before}</del><span aria-hidden="true">→</span><ins>{change.after}</ins></div></div>)}</div></details>}</div></article>)}</div>:<p className="text-muted mb-0">Belum ada audit log untuk transaksi ini.</p>}</section>;
}
