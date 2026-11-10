import { Card } from 'react-bootstrap';

export function MetricCard({label,value,note,icon,tone,children,onClick,active=false}:{label:string;value?:string;note?:React.ReactNode;icon?:React.ReactNode;tone?:'primary'|'success'|'info';children?:React.ReactNode;onClick?:()=>void;active?:boolean}) {
  const props=onClick?{role:'button',tabIndex:0,onClick,onKeyDown:(event:React.KeyboardEvent)=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();onClick();}},'aria-pressed':active}:{};
  return <Card className={`metric-card ${tone?`metric-${tone}`:''} ${onClick?'metric-card-clickable':''} ${active?'metric-card-active':''}`} {...props}><Card.Body>{icon&&<div className="metric-icon">{icon}</div>}<div className="metric-label">{label}</div>{value&&<div className="metric-value">{value}</div>}{children}<div className="metric-note">{note}</div></Card.Body></Card>;
}
