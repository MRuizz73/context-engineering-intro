"""Modelos de base de datos: choferes, camiones, documentos y usuarios."""

from datetime import date, datetime
from enum import Enum
from typing import List, Optional

from sqlmodel import Field, Relationship, SQLModel


class TipoDocumento(str, Enum):
    """Tipos de documento que puede tener un chofer o un camión."""

    CURSO = "curso"
    PERMISO = "permiso"
    LICENCIA = "licencia"
    SEGURO = "seguro"
    REVISION_TECNICA = "revision_tecnica"
    OTRO = "otro"


class EstadoDocumento(str, Enum):
    """Estado calculado de un documento según su fecha de vencimiento."""

    VIGENTE = "vigente"
    POR_VENCER = "por_vencer"
    VENCIDO = "vencido"


class Usuario(SQLModel, table=True):
    """Usuario de la aplicación (login sin verificación por email)."""

    id: Optional[int] = Field(default=None, primary_key=True)
    username: str = Field(index=True, unique=True)
    password_hash: str


class Sesion(SQLModel, table=True):
    """Sesión activa de un usuario, identificada por un token aleatorio."""

    token: str = Field(primary_key=True)
    usuario_id: int = Field(foreign_key="usuario.id")
    creada: datetime = Field(default_factory=datetime.utcnow)


class Chofer(SQLModel, table=True):
    """Chofer de la empresa de transporte."""

    id: Optional[int] = Field(default=None, primary_key=True)
    nombre: str = Field(index=True)
    apellido: str = Field(index=True)
    dni: str = Field(index=True, unique=True)
    telefono: Optional[str] = None
    email: Optional[str] = None
    activo: bool = Field(default=True)

    documentos: List["Documento"] = Relationship(
        back_populates="chofer",
        sa_relationship_kwargs={"cascade": "all, delete-orphan"},
    )


class Camion(SQLModel, table=True):
    """Camión de la flota de transporte de combustibles."""

    id: Optional[int] = Field(default=None, primary_key=True)
    patente: str = Field(index=True, unique=True)
    marca: Optional[str] = None
    modelo: Optional[str] = None
    anio: Optional[int] = None
    activo: bool = Field(default=True)

    documentos: List["Documento"] = Relationship(
        back_populates="camion",
        sa_relationship_kwargs={"cascade": "all, delete-orphan"},
    )


class Documento(SQLModel, table=True):
    """
    Curso, permiso o habilitación con fecha de vencimiento.

    Pertenece a un chofer O a un camión (exactamente uno de los dos).
    `dias_aviso` define cuántos días antes del vencimiento se activa el
    recordatorio de renovación.
    """

    id: Optional[int] = Field(default=None, primary_key=True)
    nombre: str = Field(index=True)
    tipo: TipoDocumento = Field(default=TipoDocumento.CURSO)
    fecha_emision: Optional[date] = None
    fecha_vencimiento: date = Field(index=True)
    dias_aviso: int = Field(default=30, ge=0)
    notas: Optional[str] = None
    # Reason: guarda cuándo se envió el último email para no repetir el
    # aviso todos los días; se resetea al renovar el documento.
    ultimo_aviso_email: Optional[date] = None

    chofer_id: Optional[int] = Field(default=None, foreign_key="chofer.id")
    camion_id: Optional[int] = Field(default=None, foreign_key="camion.id")

    chofer: Optional[Chofer] = Relationship(back_populates="documentos")
    camion: Optional[Camion] = Relationship(back_populates="documentos")

    def dias_restantes(self, hoy: Optional[date] = None) -> int:
        """
        Calcula los días que faltan para el vencimiento.

        Args:
            hoy (date | None): fecha de referencia; por defecto, hoy.

        Returns:
            int: días restantes (negativo si ya venció).
        """
        hoy = hoy or date.today()
        return (self.fecha_vencimiento - hoy).days

    def estado(self, hoy: Optional[date] = None) -> EstadoDocumento:
        """
        Determina el estado del documento según la fecha de referencia.

        Args:
            hoy (date | None): fecha de referencia; por defecto, hoy.

        Returns:
            EstadoDocumento: VENCIDO si pasó la fecha, POR_VENCER si está
            dentro de la ventana de aviso, VIGENTE en caso contrario.
        """
        restantes = self.dias_restantes(hoy)
        if restantes < 0:
            return EstadoDocumento.VENCIDO
        if restantes <= self.dias_aviso:
            return EstadoDocumento.POR_VENCER
        return EstadoDocumento.VIGENTE
